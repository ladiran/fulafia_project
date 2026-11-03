<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $students = StudentProfile::with('user')
            ->when($request->filled('level'), fn ($q) => $q->where('current_level', $request->integer('level')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('identifier', 'like', $term));
            })
            ->join('users', 'users.id', '=', 'student_profiles.user_id')
            ->orderBy('users.name')
            ->select('student_profiles.*')
            ->paginate(30)->withQueryString();

        return view('admin.students.index', ['students' => $students]);
    }

    public function create()
    {
        return view('admin.students.create', [
            'sessions' => AcademicSession::orderByDesc('name')->get(),
            'modes' => StudentProfile::ENTRY_MODES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'identifier' => ['required', 'string', 'max:40', 'unique:users,identifier'], // matric no. issued by the university
            'email' => ['nullable', 'email', 'unique:users,email'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'entry_mode' => ['required', Rule::in(array_keys(StudentProfile::ENTRY_MODES))],
            'entry_level' => ['nullable', Rule::in([100, 200])],
            'entry_session_id' => ['required', 'exists:academic_sessions,id'],
            'jamb_reg_no' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $level = StudentProfile::entryLevelFor($data['entry_mode']) ?? (int) ($data['entry_level'] ?? 0);
        if ($level === 0) {
            return back()->withInput()->withErrors(['entry_level' => 'Choose 100 or 200 level for a transfer student.']);
        }
        if ($data['entry_mode'] !== StudentProfile::ENTRY_TRANSFER && empty($data['jamb_reg_no'])) {
            return back()->withInput()->withErrors(['jamb_reg_no' => 'JAMB registration number is required for JAMB admissions.']);
        }

        $password = Str::password(10, symbols: false);

        $profile = DB::transaction(function () use ($data, $level, $password) {
            $user = User::create([
                'name' => $data['name'],
                'identifier' => $data['identifier'],
                'email' => ($data['email'] ?? null) ?: Str::slug($data['identifier']).'@students.invalid',
                'role' => User::ROLE_STUDENT,
                'password' => $password,
                'must_change_password' => true,
            ]);

            return StudentProfile::create([
                'user_id' => $user->id,
                'entry_mode' => $data['entry_mode'],
                'entry_level' => $level,
                'entry_session_id' => $data['entry_session_id'],
                'jamb_reg_no' => $data['jamb_reg_no'] ?? null,
                'gender' => $data['gender'],
                'phone' => $data['phone'] ?? null,
                'current_level' => $level,
            ]);
        });
        AuditLog::record('student.admitted', ['student_profile_id' => $profile->id, 'matric' => $data['identifier'], 'mode' => $data['entry_mode']]);

        // Shown once so the EO can hand it to the student, who should change it after first login.
        return redirect()->route('admin.students.show', $profile)
            ->with('status', "Student added. Temporary password (shown once): {$password}");
    }

    public function show(StudentProfile $student)
    {
        $student->load(['user', 'specialization', 'entrySession', 'registrations.course', 'registrations.session']);

        return view('admin.students.show', ['student' => $student]);
    }

    public function edit(StudentProfile $student)
    {
        return view('admin.students.edit', [
            'student' => $student->load('user'),
            'areas' => Specialization::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, StudentProfile $student): RedirectResponse
    {
        $me = $request->user();
        $rules = [
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($student->user_id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'current_level' => ['required', Rule::in([100, 200, 300, 400])],
            // Deferment and graduation have their own HoD-controlled flows.
            'status' => ['required', Rule::in(['active', 'suspended', 'withdrawn'])],
            'specialization_id' => ['nullable', 'exists:specializations,id'],
        ];
        // A name only changes for a correction by the HoD (marriage changes get their own approval flow).
        if ($me->isPrincipalAdmin()) {
            $rules['name'] = ['required', 'string', 'max:150'];
        }
        $data = $request->validate($rules);

        // Deferred and graduated records are not edited here.
        abort_if(in_array($student->status, ['deferred', 'graduated'], true), 403, 'This record is managed through its own process.');

        DB::transaction(function () use ($student, $data) {
            $student->update([
                'phone' => $data['phone'] ?? null,
                'current_level' => $data['current_level'],
                'status' => $data['status'],
                'specialization_id' => $data['specialization_id'] ?? null,
            ]);
            $userChanges = ['email' => ($data['email'] ?? null) ?: $student->user->email];
            if (isset($data['name'])) {
                $userChanges['name'] = $data['name'];
            }
            $student->user->update($userChanges);
        });
        AuditLog::record('student.updated', ['student_profile_id' => $student->id, 'level' => $data['current_level'], 'status' => $data['status']]);

        return redirect()->route('admin.students.show', $student)->with('status', 'Student updated.');
    }
}

<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Graduate;
use App\Models\GraduateResult;
use App\Models\GraduationBatch;
use App\Models\GraduationRequirement;
use App\Models\Result;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GraduationService
{
    public function __construct(private StandingService $standing, private GpaService $gpa) {}

    /**
     * Full eligibility picture for one student.
     *
     * @return array{eligible: bool, checks: array<int, array{label: string, ok: bool, detail: string}>, cgpa: ?float,
     *     credits_registered: int, credits_passed: int, required: array{registered: int, passed: int},
     *     waivable: \Illuminate\Support\Collection, waived: \Illuminate\Support\Collection}
     */
    public function evaluate(StudentProfile $student): array
    {
        $status = $this->standing->courseStatus($student);
        $waivedIds = $this->standing->waivedCourseIds($student);
        $exempt = $this->standing->exemptedCourseIds($student);          // approved transfer exemptions
        $exemptCredits = $this->standing->exemptionCredits($student);   // units the HoD let count as credits
        $required = GraduationRequirement::forMode($student->entry_mode);
        $summary = $this->gpa->summary($student);

        $unmet = fn ($courses) => $courses->map(fn (Course $c) => $c->code)->implode(', ');
        // An exempted course counts as satisfied.
        $notPassed = fn (Course $c) => ! (($status->get($c->id)['passed'] ?? false) || $exempt->contains($c->id));

        // Every core course in this student's curriculum must be registered and passed.
        $cores = $this->coreCurriculum($student)->filter($notPassed);

        // Electives linked to the student's area are compulsory too (no waiver).
        $areaCourses = $student->specialization_id
            ? Course::where('is_active', true)->where('type', Course::ELECTIVE)->where('specialization_id', $student->specialization_id)->get()->filter($notPassed)
            : collect();

        // Other registered electives: passed, or waived by the HoD.
        $electives = $status->filter(fn ($s) => $s['course']->type === Course::ELECTIVE && $s['course']->specialization_id === null)
            ->map(fn ($s) => $s['course']);
        $unresolved = $electives->filter(fn (Course $c) => $notPassed($c) && ! $waivedIds->contains($c->id));
        $waivable = $unresolved->values();
        $waived = $electives->filter(fn (Course $c) => $notPassed($c) && $waivedIds->contains($c->id))->values();

        $held = $status->filter(fn ($s) => $s['held'] && ! $waivedIds->contains($s['course']->id))->map(fn ($s) => $s['course']);

        $creditsRegistered = $status->sum(fn ($s) => $s['course']->units) + $exemptCredits;
        $creditsPassed = $summary['credits_passed'] + $exemptCredits;
        $minCgpa = collect(config('portal.degree_classes'))->last()[0];

        $checks = [
            $this->check('Active student at 400 level', $student->current_level === 400 && $student->isActive(),
                "Level {$student->current_level}L, status {$student->status}"),
            $this->check('Area of specialization chosen', $student->specialization_id !== null, $student->specialization?->name ?? 'Not chosen'),
            $this->check('All core courses registered and passed', $cores->isEmpty(), $cores->isEmpty() ? 'All passed' : 'Outstanding: '.$unmet($cores)),
            $this->check('Specialization courses registered and passed', $areaCourses->isEmpty(), $areaCourses->isEmpty() ? 'All passed' : 'Outstanding: '.$unmet($areaCourses)),
            $this->check('Registered electives passed or waived', $unresolved->isEmpty(), $unresolved->isEmpty() ? 'All resolved' : 'Outstanding: '.$unmet($unresolved).' (re-register and pass, or HoD waiver)'),
            $this->check('No withheld or incomplete results', $held->isEmpty(), $held->isEmpty() ? 'None' : 'Held: '.$unmet($held)),
            $this->check("Credits registered at least {$required['registered']}", $creditsRegistered >= $required['registered'], "{$creditsRegistered} of {$required['registered']}"),
            $this->check("Credits passed at least {$required['passed']}", $creditsPassed >= $required['passed'], "{$creditsPassed} of {$required['passed']} (waived courses do not count)"),
            $this->check('CGPA at least '.number_format($minCgpa, 2), $summary['cgpa'] !== null && $summary['cgpa'] >= $minCgpa,
                $summary['cgpa'] !== null ? 'CGPA '.number_format($summary['cgpa'], 2) : 'No published results'),
        ];

        // Transfer students need an approved credit evaluation, otherwise nobody knows what they must still take.
        if ($student->isTransfer()) {
            $approved = $student->transferEvaluation?->isApproved() ?? false;
            array_splice($checks, 1, 0, [$this->check('Transfer credit evaluation approved', $approved,
                $approved ? $exempt->count().' course(s) exempted' : 'Not yet approved by the HoD')]);
        }

        return [
            'eligible' => collect($checks)->every(fn ($c) => $c['ok']),
            'checks' => $checks,
            'cgpa' => $summary['cgpa'],
            'credits_registered' => $creditsRegistered,
            'credits_passed' => $creditsPassed,
            'required' => $required,
            'waivable' => $waivable,
            'waived' => $waived,
        ];
    }

    public function classOf(float $cgpa): ?string
    {
        foreach (config('portal.degree_classes') as [$min, $label]) {
            if ($cgpa >= $min) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Approve a batch: re-check every student, then write the permanent records in one transaction.
     */
    public function graduate(GraduationBatch $batch, User $approver): void
    {
        if ($batch->status !== 'proposed') {
            throw ValidationException::withMessages(['batch' => 'Only a proposed batch can be approved.']);
        }

        $batch->load('session', 'students.user', 'students.specialization', 'students.entrySession');

        $failures = [];
        foreach ($batch->students as $student) {
            if (! $this->evaluate($student)['eligible']) {
                $failures[] = $student->user->identifier;
            }
        }
        if ($failures) {
            throw ValidationException::withMessages(['batch' => 'No longer eligible: '.implode(', ', $failures).'. Remove them and propose again.']);
        }

        DB::transaction(function () use ($batch, $approver) {
            foreach ($batch->students as $student) {
                $this->createRecord($batch, $student, $approver);
            }
            $batch->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);
        });

        AuditLog::record('graduation.approved', ['batch_id' => $batch->id, 'students' => $batch->students->count()]);
    }

    private function createRecord(GraduationBatch $batch, StudentProfile $student, User $approver): void
    {
        $check = $this->evaluate($student);
        $cgpa = round($check['cgpa'], 2);

        $graduate = Graduate::create([
            'student_profile_id' => $student->id,
            'graduation_batch_id' => $batch->id,
            'matric' => $student->user->identifier,
            'name' => $student->user->name,
            'gender' => $student->gender,
            'entry_mode' => $student->entry_mode,
            'entry_session' => $student->entrySession?->name,
            'graduation_session' => $batch->session->name,
            'graduation_year' => now()->year,
            'degree' => config('portal.degree'),
            'specialization' => $student->specialization?->name,
            'cgpa' => $cgpa,
            'credits_registered' => $check['credits_registered'],
            'credits_passed' => $check['credits_passed'],
            'class_of_degree' => $this->classOf($cgpa),
            'verification_code' => $this->newCode(),
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        // Frozen copy of every published attempt.
        $registrations = $student->registrations()->where('status', 'approved')->with(['course', 'session'])->get();
        $results = Result::whereIn('course_registration_id', $registrations->pluck('id'))
            ->whereHas('offering', fn ($q) => $q->where('status', CourseOffering::PUBLISHED))->get()->keyBy('course_registration_id');

        foreach ($registrations->sortBy(fn ($r) => $r->session->name.$r->semester.$r->course->code) as $registration) {
            if ($result = $results->get($registration->id)) {
                GraduateResult::create([
                    'graduate_id' => $graduate->id,
                    'session' => $registration->session->name,
                    'semester' => $registration->semester,
                    'course_code' => $registration->course->code,
                    'course_title' => $registration->course->title,
                    'units' => $registration->course->units,
                    'course_type' => $registration->course->type,
                    'ca' => $result->ca, 'ca2' => $result->ca2, 'exam' => $result->exam, 'total' => $result->total,
                    'grade' => $result->grade, 'grade_point' => $result->grade_point, 'flag' => $result->flag,
                ]);
            }
        }
        foreach ($check['waived'] as $course) {
            $last = $registrations->where('course_id', $course->id)->sortByDesc(fn ($r) => $r->session->name)->first();
            GraduateResult::create([
                'graduate_id' => $graduate->id,
                'session' => $last?->session->name ?? $batch->session->name,
                'semester' => $last?->semester ?? $course->semester,
                'course_code' => $course->code, 'course_title' => $course->title, 'units' => $course->units,
                'course_type' => $course->type, 'waived' => true,
            ]);
        }

        // Exempted (transfer) courses appear on the permanent record too.
        foreach ($this->standing->exemptions($student) as $exemption) {
            GraduateResult::create([
                'graduate_id' => $graduate->id,
                'session' => $student->entrySession?->name ?? $batch->session->name,
                'semester' => $exemption->course->semester,
                'course_code' => $exemption->course->code, 'course_title' => $exemption->course->title,
                'units' => $exemption->course->units, 'course_type' => $exemption->course->type,
                'exempted' => true,
            ]);
        }

        $student->update(['status' => 'graduated']);
        $student->user->update(['role' => User::ROLE_ALUMNI]);
        DB::table('student_level_history')->insert([
            'student_profile_id' => $student->id, 'academic_session_id' => $batch->academic_session_id,
            'from_level' => 400, 'to_level' => 400, 'outcome' => 'graduated', 'decided_by' => $approver->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Core courses this student must pass, given how they were admitted (exemptions not yet removed). */
    public function requiredCoreCourses(StudentProfile $student)
    {
        return $this->coreCurriculum($student);
    }

    /** Core courses this student must pass, given how they were admitted. */
    private function coreCurriculum(StudentProfile $student)
    {
        return Course::where('is_active', true)->where('type', Course::CORE)
            ->where(function ($q) use ($student) {
                // A transfer student owes the whole programme except what the approved evaluation exempts.
                if ($student->isTransfer()) {
                    $q->where('level', '>=', 100);

                    return;
                }
                $q->where('level', '>=', $student->entry_level);
                // Direct Entry students skip 100L except GST courses.
                if ($student->entry_mode === StudentProfile::ENTRY_DIRECT) {
                    $q->orWhere(fn ($g) => $g->where('level', 100)->where('is_gst', true));
                }
            })->get();
    }

    private function check(string $label, bool $ok, string $detail): array
    {
        return compact('label', 'ok', 'detail');
    }

    private function newCode(): string
    {
        do {
            $code = Str::upper(Str::random(10));
        } while (Graduate::where('verification_code', $code)->exists());

        return $code;
    }
}

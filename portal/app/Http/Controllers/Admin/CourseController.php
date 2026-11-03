<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Specialization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $courses = Course::with('specialization')
            ->when($request->filled('level'), fn ($q) => $q->where('level', $request->integer('level')))
            ->when($request->filled('semester'), fn ($q) => $q->where('semester', $request->integer('semester')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderBy('level')->orderBy('semester')->orderBy('code')
            ->get();

        return view('admin.courses.index', ['courses' => $courses]);
    }

    public function create()
    {
        return view('admin.courses.form', ['course' => new Course(['units' => 3, 'level' => 100, 'semester' => 1, 'type' => 'C', 'is_active' => true]), 'areas' => $this->areas()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $course = Course::create($this->validated($request));
        AuditLog::record('course.created', ['code' => $course->code]);

        return redirect()->route('admin.courses.index')->with('status', "{$course->code} added.");
    }

    public function edit(Course $course)
    {
        return view('admin.courses.form', ['course' => $course, 'areas' => $this->areas()]);
    }

    public function update(Request $request, Course $course): RedirectResponse
    {
        $course->update($this->validated($request, $course));
        AuditLog::record('course.updated', ['code' => $course->code]);

        return redirect()->route('admin.courses.index')->with('status', "{$course->code} updated.");
    }

    private function areas()
    {
        return Specialization::where('is_active', true)->orderBy('name')->get();
    }

    private function validated(Request $request, ?Course $course = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('courses', 'code')->ignore($course?->id)],
            'title' => ['required', 'string', 'max:180'],
            'units' => ['required', 'integer', 'between:1,8'],
            'level' => ['required', Rule::in([100, 200, 300, 400])],
            'semester' => ['required', Rule::in([1, 2])],
            'type' => ['required', Rule::in(['C', 'E'])],
            'specialization_id' => ['nullable', Rule::exists('specializations', 'id')],
        ]);

        $data['code'] = strtoupper(preg_replace('/\s+/', ' ', trim($data['code'])));
        // Only electives link to an area (they are then mandatory for that area's students).
        if ($data['type'] === 'C') {
            $data['specialization_id'] = null;
        }
        $data['is_gst'] = $request->boolean('is_gst');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}

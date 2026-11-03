<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseWaiver;
use App\Models\Result;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * Where a student stands on every course they registered, from PUBLISHED results only.
 * A course is "passed" if any attempt passed.
 */
class StandingService
{
    /**
     * @return Collection<int, array{course: Course, passed: bool, failed: bool, held: bool, pending: bool}> keyed by course id
     */
    public function courseStatus(StudentProfile $student): Collection
    {
        $registrations = $student->registrations()->where('status', 'approved')->with('course')->get();

        $results = Result::whereIn('course_registration_id', $registrations->pluck('id'))
            ->whereHas('offering', fn ($q) => $q->where('status', CourseOffering::PUBLISHED))
            ->get()->keyBy('course_registration_id');

        $status = [];
        foreach ($registrations as $registration) {
            $id = $registration->course_id;
            $status[$id] ??= ['course' => $registration->course, 'passed' => false, 'graded_fail' => false, 'held' => false];

            $result = $results->get($registration->id);
            if (! $result) {
                continue;
            }
            if ($result->isPassed()) {
                $status[$id]['passed'] = true;
            } elseif ($result->isGraded()) {
                $status[$id]['graded_fail'] = true;
            } else { // incomplete or withheld
                $status[$id]['held'] = true;
            }
        }

        return collect($status)->map(fn ($s) => [
            'course' => $s['course'],
            'passed' => $s['passed'],
            'failed' => ! $s['passed'] && $s['graded_fail'],
            'held' => ! $s['passed'] && $s['held'],
            'pending' => ! $s['passed'] && ! $s['graded_fail'] && ! $s['held'],
        ]);
    }

    /**
     * Exemptions in force: only from an APPROVED transfer evaluation.
     *
     * @return Collection<int, \App\Models\CourseExemption>
     */
    public function exemptions(StudentProfile $student): Collection
    {
        return \App\Models\CourseExemption::where('student_profile_id', $student->id)
            ->whereHas('evaluation', fn ($q) => $q->where('status', 'approved'))
            ->with('course')->get();
    }

    /** @return Collection<int, int> */
    public function exemptedCourseIds(StudentProfile $student): Collection
    {
        return $this->exemptions($student)->pluck('course_id');
    }

    /** Credit units of exempted courses that the HoD allowed to count as credits passed. */
    public function exemptionCredits(StudentProfile $student): int
    {
        return (int) $this->exemptions($student)->where('counts_as_credit', true)->sum(fn ($e) => $e->course->units);
    }

    /** @return Collection<int, int> course ids with an active HoD waiver */
    public function waivedCourseIds(StudentProfile $student): Collection
    {
        return CourseWaiver::where('student_profile_id', $student->id)->active()->pluck('course_id');
    }

    /** Courses failed and not waived: they must be registered again and passed. @return Collection<int, int> */
    public function carryOverCourseIds(StudentProfile $student): Collection
    {
        $waived = $this->waivedCourseIds($student);

        return $this->courseStatus($student)
            ->filter(fn ($s) => $s['failed'] && ! $waived->contains($s['course']->id))
            ->keys();
    }
}

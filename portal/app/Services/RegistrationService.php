<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Which courses a student may register in the current semester, and what they must take. */
class RegistrationService
{
    /** Why the student cannot register right now, or null when they can. */
    public function blockedReason(StudentProfile $student, ?AcademicSession $session): ?string
    {
        return match (true) {
            $session === null => 'No academic session is open for registration.',
            ! $student->isActive() => 'Your status is "'.$student->status.'", so you cannot register courses.',
            // The department must first decide which courses a transfer student is exempted from.
            $student->isTransfer() && ! ($student->transferEvaluation?->isApproved() ?? false)
                => 'Your transfer credit evaluation has not been approved yet. You can register once the department has approved it.',
            $student->current_level === 400 && $student->specialization_id === null
                => 'Choose your area of specialization before registering 400 level courses.',
            default => $this->calendar->registrationBlocked($session),
        };
    }

    public function __construct(private StandingService $standing, private CalendarService $calendar) {}

    /**
     * Courses open to the student this semester. Each carries `carry_over` (failed earlier and not waived,
     * so it must be registered again) and `mandatory` (core, area-linked elective, or carry-over).
     *
     * @return Collection<int, Course>
     */
    public function availableCourses(StudentProfile $student, AcademicSession $session): Collection
    {
        $carryIds = $this->standing->carryOverCourseIds($student);
        $exemptIds = $this->standing->exemptedCourseIds($student);

        $courses = Course::with('specialization')
            ->where('is_active', true)
            ->whereNotIn('id', $exemptIds)
            ->where('semester', $session->current_semester)
            ->where(function ($q) use ($student) {
                $q->where('level', $student->current_level);
                // Direct Entry students skip 100L courses except GST.
                if ($student->entry_mode === StudentProfile::ENTRY_DIRECT && $student->current_level > 100) {
                    $q->orWhere(fn ($g) => $g->where('level', 100)->where('is_gst', true));
                }
            })
            ->where(function ($q) use ($student) {
                $q->whereNull('specialization_id');
                if ($student->specialization_id) {
                    $q->orWhere('specialization_id', $student->specialization_id);
                }
            })
            ->orderBy('code')
            ->get();

        // Failed courses come back in their own semester, whatever level they were first taken at.
        $carryCourses = Course::with('specialization')->where('is_active', true)
            ->whereIn('id', $carryIds)->where('semester', $session->current_semester)->get();

        // A transfer student also owes core courses from earlier levels that were not exempted and never taken.
        $outstanding = collect();
        if ($student->isTransfer()) {
            $taken = $this->standing->courseStatus($student);
            $outstanding = Course::with('specialization')->where('is_active', true)->where('type', Course::CORE)
                ->where('level', '<', $student->current_level)->where('semester', $session->current_semester)
                ->whereNotIn('id', $exemptIds)->get()
                ->reject(fn (Course $c) => $taken->has($c->id));
        }
        $outstandingIds = $outstanding->pluck('id');

        $list = $courses->concat($carryCourses)->concat($outstanding)->unique('id')->sortBy('code')->values()
            ->each(function (Course $c) use ($student, $carryIds, $outstandingIds) {
                $c->setAttribute('carry_over', $carryIds->contains($c->id));
                $c->setAttribute('outstanding', $outstandingIds->contains($c->id));
                $c->setAttribute('mandatory', $c->carry_over || $c->outstanding || $c->isMandatoryFor($student));
                $c->setAttribute('over_cap', false);
            });

        // At most 24 units a semester. Courses already approved use their share first; then failed courses (carry-overs)
        // have priority, then the other required courses, in code order. A required course that no longer fits
        // is not demanded this semester.
        $approvedIds = $this->approvedIds($student, $session);
        $capacity = $this->maxUnits() - $this->approvedUnits($student, $session);
        $list->filter(fn (Course $c) => $c->mandatory && ! $approvedIds->contains($c->id))
            ->sortBy(fn (Course $c) => ($c->carry_over ? '0' : ($c->outstanding ? '1' : '2')).$c->code)
            ->each(function (Course $c) use (&$capacity) {
                if ($c->units <= $capacity) {
                    $capacity -= $c->units;
                } else {
                    $c->setAttribute('mandatory', false);
                    $c->setAttribute('over_cap', true);
                }
            });

        return $list;
    }

    public function maxUnits(): int
    {
        return (int) config('portal.max_units_per_semester');
    }

    /** @return Collection<int, int> */
    private function approvedIds(StudentProfile $student, AcademicSession $session): Collection
    {
        return $student->registrations()->where('academic_session_id', $session->id)
            ->where('semester', $session->current_semester)->where('status', CourseRegistration::APPROVED)->pluck('course_id');
    }

    private function approvedUnits(StudentProfile $student, AcademicSession $session): int
    {
        return (int) Course::whereIn('id', $this->approvedIds($student, $session))->sum('units');
    }

    /**
     * Replace the student's pending/rejected registrations for the semester with the chosen courses.
     * Approved registrations are locked and count as already satisfied.
     *
     * @param  array<int>  $courseIds
     */
    public function submit(StudentProfile $student, AcademicSession $session, array $courseIds): void
    {
        $available = $this->availableCourses($student, $session)->keyBy('id');

        $approvedIds = $this->approvedIds($student, $session);

        $chosen = collect($courseIds)->map(fn ($id) => (int) $id)->unique()->diff($approvedIds);

        if ($chosen->diff($available->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['courses' => 'One or more selected courses are not open to you.']);
        }

        $missing = $available
            ->filter(fn (Course $c) => $c->mandatory)
            ->reject(fn (Course $c) => $approvedIds->contains($c->id) || $chosen->contains($c->id));

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'courses' => 'You must register all core and specialization courses: '.$missing->pluck('code')->implode(', ').'.',
            ]);
        }

        $units = $this->approvedUnits($student, $session) + $chosen->sum(fn ($id) => $available[$id]->units);
        if ($units > $this->maxUnits()) {
            throw ValidationException::withMessages([
                'courses' => "You can register at most {$this->maxUnits()} units in a semester. Your selection is {$units} units. Remove an optional course.",
            ]);
        }

        $student->registrations()
            ->where('academic_session_id', $session->id)
            ->where('semester', $session->current_semester)
            ->whereIn('status', [CourseRegistration::PENDING, CourseRegistration::REJECTED])
            ->delete();

        foreach ($chosen as $courseId) {
            $student->registrations()->create([
                'course_id' => $courseId,
                'academic_session_id' => $session->id,
                'semester' => $session->current_semester,
                'level_at_registration' => $student->current_level,
                'status' => CourseRegistration::PENDING,
            ]);
        }
    }
}

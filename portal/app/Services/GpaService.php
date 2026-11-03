<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\Result;
use App\Models\StudentProfile;

/**
 * Student-visible results and GPA/CGPA, built only from PUBLISHED result sheets.
 * Every graded attempt counts toward GPA/CGPA; credits passed count each course once.
 */
class GpaService
{
    /**
     * @return array{semesters: array<int, array>, tcu: int, tgp: int, cgpa: ?float, credits_passed: int}
     */
    public function summary(StudentProfile $student): array
    {
        $results = Result::query()
            ->whereHas('offering', fn ($q) => $q->where('status', CourseOffering::PUBLISHED))
            ->whereHas('registration', fn ($q) => $q->where('student_profile_id', $student->id))
            ->with(['registration.course', 'registration.session'])
            ->get()
            ->sortBy(fn (Result $r) => $r->registration->session->name.'-'.$r->registration->semester.'-'.$r->registration->course->code);

        $semesters = [];
        $cumTcu = 0;
        $cumTgp = 0;
        $passedCourses = [];

        foreach ($results->groupBy(fn (Result $r) => $r->registration->session->name.'|'.$r->registration->semester) as $key => $rows) {
            [$session, $semester] = explode('|', $key);

            $tcu = 0;
            $tgp = 0;
            foreach ($rows as $r) {
                $units = $r->registration->course->units;
                if ($r->isGraded() && $r->flag !== 'withheld') {
                    $tcu += $units;
                    $tgp += $units * $r->grade_point;
                    if ($r->isPassed()) {
                        $passedCourses[$r->registration->course_id] = $units;
                    }
                }
            }
            $cumTcu += $tcu;
            $cumTgp += $tgp;

            $semesters[] = [
                'session' => $session,
                'semester' => (int) $semester,
                'results' => $rows->values(),
                'tcu' => $tcu,
                'tgp' => $tgp,
                'gpa' => $tcu ? round($tgp / $tcu, 2) : null,
                'cgpa' => $cumTcu ? round($cumTgp / $cumTcu, 2) : null,
            ];
        }

        return [
            'semesters' => $semesters,
            'tcu' => $cumTcu,
            'tgp' => $cumTgp,
            'cgpa' => $cumTcu ? round($cumTgp / $cumTcu, 2) : null,
            'credits_passed' => array_sum($passedCourses),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\Result;

/** Data for the "Examination result" sheet of one course in one session (the department's course result template). */
class CourseSheetService
{
    /** @return array<string, mixed> */
    public function build(CourseOffering $offering): array
    {
        $offering->loadMissing(['course', 'session', 'lecturer']);
        $registrations = $offering->approvedRegistrations()->with('student.user')->get()
            ->sortBy(fn ($r) => $r->student->user->identifier, SORT_NATURAL)->values();
        $results = Result::where('course_offering_id', $offering->id)->get()->keyBy('course_registration_id');
        $pass = config('portal.pass_mark');

        $counts = array_fill_keys(['A', 'B', 'C', 'D', 'E', 'F'], 0);
        $rows = $registrations->map(function ($reg, $i) use ($results, $pass, &$counts) {
            $r = $results->get($reg->id);

            return [
                'sn' => $i + 1,
                'matric' => $reg->student->user->identifier,
                'ca1' => $this->num($r?->ca),
                'ca2' => $this->num($r?->ca2),
                'exam' => $this->num($r?->exam),
                'total' => $this->num($r?->total),
                'grade' => $r?->grade,
                'remark' => $this->remark($r, $pass),
                'graded' => $r?->grade !== null,
            ];
        })->each(function ($row) use (&$counts) {
            if ($row['graded']) {
                $counts[$row['grade']]++;
            }
        })->all();

        return [
            'session' => $offering->session->name,
            'semester' => $offering->course->semester == 1 ? 'First' : 'Second',
            'course' => $offering->course,
            'lecturer' => $offering->lecturer?->name,
            'status' => $offering->status,
            'rows' => $rows,
            'counts' => $counts,
            'total' => array_sum($counts),
        ];
    }

    private function remark(?Result $r, int|float $pass): string
    {
        return match (true) {
            $r === null => '',
            $r->flag === 'absent' => 'Absent',
            $r->flag === 'incomplete' => 'Incomplete',
            $r->flag === 'withheld' => 'Withheld',
            $r->total === null => '',
            $r->total >= $pass => 'Pass',
            default => 'Fail',
        };
    }

    private function num(?float $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

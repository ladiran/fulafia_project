<?php

namespace App\Services;

class GradingService
{
    /** @return array{0: string, 1: int} [grade, grade point] for a total score out of 100 */
    public function grade(float $total): array
    {
        foreach (config('portal.grading') as [$min, $grade, $point]) {
            if ($total >= $min) {
                return [$grade, $point];
            }
        }

        return ['F', 0];
    }

    /**
     * Derive total, grade and point from entered scores.
     * - absent: scored as 0 (grade F).
     * - incomplete / withheld: not graded.
     * - otherwise graded only once both CA and exam are present.
     *
     * @return array{total: ?float, grade: ?string, grade_point: ?int}
     */
    public function evaluate(?float $ca, ?float $exam, ?string $flag, ?float $ca2 = null): array
    {
        if ($flag === 'absent') {
            return ['total' => 0.0, 'grade' => 'F', 'grade_point' => 0];
        }
        if ($flag !== null || $ca === null || $exam === null) {
            return ['total' => null, 'grade' => null, 'grade_point' => null];
        }

        // CA 1 + CA 2 (CA 2 is optional) + exam.
        $total = round($ca + ($ca2 ?? 0) + $exam, 2);
        [$grade, $point] = $this->grade($total);

        return ['total' => $total, 'grade' => $grade, 'grade_point' => $point];
    }
}

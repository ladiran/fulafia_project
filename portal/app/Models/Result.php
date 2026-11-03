<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Result extends Model
{
    public const FLAGS = ['absent', 'incomplete', 'withheld'];

    protected $fillable = [
        'course_registration_id', 'course_offering_id', 'ca', 'ca2', 'exam', 'total', 'grade', 'grade_point', 'flag',
    ];

    protected function casts(): array
    {
        return ['ca' => 'float', 'ca2' => 'float', 'exam' => 'float', 'total' => 'float', 'grade_point' => 'integer'];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(CourseRegistration::class, 'course_registration_id');
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    /** Counts toward GPA: has a grade. Incomplete and withheld results are not graded. */
    public function isGraded(): bool
    {
        return $this->grade !== null;
    }

    public function isPassed(): bool
    {
        return $this->isGraded() && $this->total >= config('portal.pass_mark');
    }
}

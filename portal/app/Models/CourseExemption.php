<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseExemption extends Model
{
    protected $fillable = [
        'transfer_evaluation_id', 'student_profile_id', 'course_id',
        'previous_code', 'previous_title', 'previous_grade', 'counts_as_credit',
    ];

    protected function casts(): array
    {
        return ['counts_as_credit' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(TransferEvaluation::class, 'transfer_evaluation_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseWaiver extends Model
{
    protected $fillable = ['student_profile_id', 'course_id', 'reason', 'waived_by', 'revoked_at', 'revoked_by'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}

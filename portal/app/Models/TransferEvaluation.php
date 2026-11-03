<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferEvaluation extends Model
{
    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'student_profile_id', 'previous_institution', 'previous_programme', 'status',
        'evaluated_by', 'submitted_at', 'approved_by', 'approved_at', 'note',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TransferDocument::class);
    }

    public function exemptions(): HasMany
    {
        return $this->hasMany(CourseExemption::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}

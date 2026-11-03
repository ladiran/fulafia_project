<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseOffering extends Model
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const VETTED = 'vetted';
    public const APPROVED = 'approved';
    public const PUBLISHED = 'published';

    protected $fillable = [
        'course_id', 'academic_session_id', 'lecturer_id', 'status', 'submitted_at',
        'vetted_by', 'vetted_at', 'approved_by', 'approved_at', 'published_at', 'return_note',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'vetted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    /** Registrations that were approved by the Level Adviser: only these students are scored. */
    public function approvedRegistrations(): \Illuminate\Database\Eloquent\Builder
    {
        return CourseRegistration::query()
            ->where('course_id', $this->course_id)
            ->where('academic_session_id', $this->academic_session_id)
            ->where('status', CourseRegistration::APPROVED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }
}

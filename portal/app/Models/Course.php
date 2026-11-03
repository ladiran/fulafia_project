<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Course extends Model
{
    public const CORE = 'C';
    public const ELECTIVE = 'E';

    protected $fillable = ['code', 'title', 'units', 'level', 'semester', 'type', 'specialization_id', 'is_gst', 'is_active'];

    protected function casts(): array
    {
        return ['is_gst' => 'boolean', 'is_active' => 'boolean'];
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
    }

    public function isCore(): bool
    {
        return $this->type === self::CORE;
    }

    /** Must be registered and passed by the given student (core, or elective linked to their area). */
    public function isMandatoryFor(StudentProfile $student): bool
    {
        return $this->isCore()
            || ($this->specialization_id !== null && $this->specialization_id === $student->specialization_id);
    }

    public function label(): string
    {
        return "{$this->code} ({$this->type})";
    }
}

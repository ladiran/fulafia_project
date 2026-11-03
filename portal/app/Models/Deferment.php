<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deferment extends Model
{
    protected $fillable = ['student_profile_id', 'academic_session_id', 'reason', 'initiated_by', 'status', 'resolved_at', 'resolved_by'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    /** Still holding the student out: not yet returned or withdrawn. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['active', 'lapsed'], true);
    }
}

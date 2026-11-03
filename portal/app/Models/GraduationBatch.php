<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GraduationBatch extends Model
{
    protected $fillable = ['academic_session_id', 'status', 'proposed_by', 'approved_by', 'approved_at', 'note'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(StudentProfile::class, 'graduation_batch_items', 'graduation_batch_id', 'student_profile_id')->withTimestamps();
    }
}

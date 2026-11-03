<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An extra session at 400 level that the Senate granted on appeal, recorded by the HoD. */
class ResidencyExtension extends Model
{
    protected $fillable = ['student_profile_id', 'sessions_granted', 'senate_reference', 'note', 'granted_by'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}

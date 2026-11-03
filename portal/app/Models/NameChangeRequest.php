<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NameChangeRequest extends Model
{
    public const DOCUMENT_TYPES = ['certificate' => 'Marriage certificate', 'affidavit' => 'Affidavit'];

    protected $fillable = [
        'student_profile_id', 'current_name', 'new_name', 'document_type', 'marriage_document_path', 'newspaper_path',
        'status', 'verified_by', 'verified_at', 'decided_by', 'decided_at', 'note',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['submitted', 'verified'], true);
    }
}

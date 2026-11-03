<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enquiry extends Model
{
    public const CATEGORIES = [
        'deferment' => 'Deferment of admission',
        'name_change' => 'Change of name',
        'results' => 'Results',
        'other' => 'Other',
    ];

    protected $fillable = ['student_profile_id', 'category', 'subject', 'message', 'status', 'response', 'responded_by', 'responded_at'];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Download extends Model
{
    public const CATEGORIES = [
        'handbook' => 'Handbook',
        'forms' => 'Forms',
        'timetables' => 'Timetables',
        'past_questions' => 'Past questions',
        'other' => 'Other',
    ];

    protected $fillable = ['title', 'description', 'category', 'media_id', 'is_published', 'download_count'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    public const TYPES = ['news' => 'News', 'announcement' => 'Announcement'];

    protected $fillable = ['type', 'title', 'slug', 'summary', 'body', 'image_media_id', 'is_pinned', 'is_published', 'published_at', 'created_by'];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean', 'is_published' => 'boolean', 'published_at' => 'datetime'];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'image_media_id');
    }

    /** Visible on the public site: published and not scheduled for the future. */
    public function scopeLive($query)
    {
        return $query->where('is_published', true)->where('published_at', '<=', now());
    }
}

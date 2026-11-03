<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaFile extends Model
{
    protected $fillable = ['path', 'original_name', 'mime', 'size', 'uploaded_by'];

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function url(): string
    {
        return route('media', $this);
    }
}

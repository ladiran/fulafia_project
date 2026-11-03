<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentImport extends Model
{
    protected $attributes = ['status' => 'previewed'];

    protected $fillable = [
        'user_id', 'original_name', 'status', 'default_session_id', 'total_rows', 'valid_rows', 'error_rows',
        'created_count', 'errors', 'file_path', 'credentials_path', 'credentials_downloaded_at',
    ];

    protected function casts(): array
    {
        return ['errors' => 'array', 'credentials_downloaded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'default_session_id');
    }
}

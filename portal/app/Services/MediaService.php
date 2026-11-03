<?php

namespace App\Services;

use App\Models\MediaFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaService
{
    /** Extension => the MIME type we serve it as (never trust the client's type, never serve SVG). */
    public const TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function store(UploadedFile $file, ?int $userId = null): MediaFile
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return MediaFile::create([
            'path' => $file->storeAs('cms', bin2hex(random_bytes(16)).'.'.$extension, 'local'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => self::TYPES[$extension] ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'uploaded_by' => $userId,
        ]);
    }

    /** Replace an old file (if any) with a new upload and return the new record. */
    public function replace(?MediaFile $old, UploadedFile $file, ?int $userId = null): MediaFile
    {
        $new = $this->store($file, $userId);
        if ($old) {
            $this->delete($old);
        }

        return $new;
    }

    public function delete(?MediaFile $media): void
    {
        if (! $media) {
            return;
        }
        Storage::disk('local')->delete($media->path);
        $media->delete();
    }
}

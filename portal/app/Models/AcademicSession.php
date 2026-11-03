<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicSession extends Model
{
    protected $fillable = ['name', 'is_current', 'current_semester', 'specialization_window_open', 'rolled_over_at'];

    protected function casts(): array
    {
        return [
            'rolled_over_at' => 'datetime',
            'is_current' => 'boolean',
            'specialization_window_open' => 'boolean',
        ];
    }

    public static function current(): ?self
    {
        return static::where('is_current', true)->first();
    }

    /** Make this the only current session. */
    public function makeCurrent(): void
    {
        static::query()->update(['is_current' => false]);
        $this->update(['is_current' => true]);
    }
}

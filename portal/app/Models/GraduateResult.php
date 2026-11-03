<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Frozen copy of one course outcome at graduation. Append-only like Graduate. */
class GraduateResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['waived' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Graduate results are permanent and cannot be changed.'));
        static::deleting(fn () => throw new \LogicException('Graduate results are permanent and cannot be deleted.'));
    }
}

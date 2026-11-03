<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permanent graduate record. Append-only: updates and deletes are refused here, and the database user
 * should also be denied UPDATE/DELETE on this table in production. Corrections need an HoD-authorised
 * amendment process (not part of this model).
 */
class Graduate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'cgpa' => 'float'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Graduate records are permanent and cannot be changed.'));
        static::deleting(fn () => throw new \LogicException('Graduate records are permanent and cannot be deleted.'));
    }

    public function results(): HasMany
    {
        return $this->hasMany(GraduateResult::class);
    }

    public function studentProfile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(GraduateAmendment::class);
    }

    /**
     * The record as it stands today: the permanent original with every approved correction laid over it.
     * Always read through this when showing a graduate. The returned copy is for display only.
     */
    public function effective(): static
    {
        return app(\App\Services\GraduateAmendmentService::class)->effective($this);
    }

    public function isAmended(): bool
    {
        return $this->relationLoaded('amendments') && $this->amendments->where('status', 'approved')->isNotEmpty();
    }
}

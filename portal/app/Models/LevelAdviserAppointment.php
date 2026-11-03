<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LevelAdviserAppointment extends Model
{
    public const TENURE_YEARS = 2;
    public const REMINDER_MONTHS = 2;
    public const LEVELS = [100, 200, 300, 400];

    protected $fillable = [
        'user_id', 'level', 'starts_on', 'ends_on', 'appointed_by',
        'reminder_sent_at', 'removed_at', 'removed_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'reminder_sent_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appointed_by');
    }

    /** Appointments still holding the post; expiry does not end it, only removal does. */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /** Holding appointments whose end date falls within the reminder window (or has passed). */
    public function scopeDueForReminder(Builder $query): Builder
    {
        return $query->holding()
            ->whereDate('ends_on', '<=', now()->addMonths(self::REMINDER_MONTHS)->toDateString());
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->ends_on->lt(today());
    }

    public function isExpiring(): bool
    {
        return ! $this->isExpired()
            && $this->ends_on->lte(today()->addMonths(self::REMINDER_MONTHS));
    }

    /** ACTIVE | EXPIRING | EXPIRED | REMOVED */
    public function status(): string
    {
        return match (true) {
            $this->isRemoved() => 'REMOVED',
            $this->isExpired() => 'EXPIRED',
            $this->isExpiring() => 'EXPIRING',
            default => 'ACTIVE',
        };
    }

    public static function tenureEndFrom(\DateTimeInterface $start): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::instance($start)->addYears(self::TENURE_YEARS);
    }
}

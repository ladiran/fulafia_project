<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentProfile extends Model
{
    public const ENTRY_REGULAR = 'regular';
    public const ENTRY_DIRECT = 'direct_entry';
    public const ENTRY_TRANSFER = 'transfer';

    public const ENTRY_MODES = [
        self::ENTRY_REGULAR => 'Regular (JAMB, 100L)',
        self::ENTRY_DIRECT => 'Direct Entry (JAMB, 200L)',
        self::ENTRY_TRANSFER => 'Transfer (100L or 200L)',
    ];

    public const STATUSES = ['active', 'deferred', 'suspended', 'withdrawn', 'graduated'];

    protected $attributes = ['status' => 'active'];

    protected $fillable = [
        'user_id', 'entry_mode', 'entry_level', 'entry_session_id', 'jamb_reg_no', 'gender',
        'phone', 'current_level', 'status', 'specialization_id', 'sessions_at_400', 'withdrawal_reason',
    ];

    public function residencyExtensions(): HasMany
    {
        return $this->hasMany(ResidencyExtension::class);
    }

    /**
     * Sessions a student may spend at 400 level in total: the normal one, the extra sessions the rules allow,
     * and whatever the Senate has approved on appeal.
     */
    public function allowedSessionsAt400(): int
    {
        return 1 + (int) config('portal.extra_sessions_at_400') + (int) $this->residencyExtensions()->sum('sessions_granted');
    }

    /** Sessions at 400 level still available after the ones already finished. */
    public function sessionsAt400Left(): int
    {
        return max(0, $this->allowedSessionsAt400() - (int) $this->sessions_at_400);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entrySession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'entry_session_id');
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(CourseRegistration::class);
    }

    public function transferEvaluation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TransferEvaluation::class);
    }

    public function isTransfer(): bool
    {
        return $this->entry_mode === self::ENTRY_TRANSFER;
    }

    public function deferments(): HasMany
    {
        return $this->hasMany(Deferment::class);
    }

    public function nameHistory(): HasMany
    {
        return $this->hasMany(StudentNameHistory::class)->latest('effective_date');
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(CourseWaiver::class);
    }

    public function graduate(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Graduate::class);
    }

    public function getMatricAttribute(): ?string
    {
        return $this->user->identifier;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public static function entryLevelFor(string $mode): ?int
    {
        return match ($mode) {
            self::ENTRY_REGULAR => 100,
            self::ENTRY_DIRECT => 200,
            default => null, // transfer: chosen (100 or 200)
        };
    }
}

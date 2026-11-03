<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'identifier', 'role', 'permissions', 'is_active', 'password', 'must_change_password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_PRINCIPAL_ADMIN = 'principal_admin'; // HoD
    public const ROLE_SECONDARY_ADMIN = 'secondary_admin'; // Examination Officer
    public const ROLE_ADMIN = 'admin';                     // appointed by the HoD
    public const ROLE_LECTURER = 'lecturer';
    public const ROLE_STUDENT = 'student';
    public const ROLE_ALUMNI = 'alumni';

    public const ADMIN_ROLES = [
        self::ROLE_PRINCIPAL_ADMIN,
        self::ROLE_SECONDARY_ADMIN,
        self::ROLE_ADMIN,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public const STAFF_ROLES = [self::ROLE_PRINCIPAL_ADMIN, self::ROLE_SECONDARY_ADMIN, self::ROLE_ADMIN, self::ROLE_LECTURER];

    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }

    /** End every session of this user (database session driver), optionally keeping one. */
    public function endSessions(?string $exceptId = null): void
    {
        if (config('session.driver') === 'database') {
            \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))
                ->where('user_id', $this->id)
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->delete();
        }
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** Admins (HoD, EO, appointed) must use two-factor login when enforcement is on (it is by default). */
    public function mustUseTwoFactor(): bool
    {
        return $this->isAdmin() && config('portal.require_admin_2fa');
    }

    public function isPrincipalAdmin(): bool
    {
        return $this->role === self::ROLE_PRINCIPAL_ADMIN;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, self::ADMIN_ROLES, true);
    }

    /** HoD and EO hold every permission; appointed admins only the ones granted to them. */
    public function hasPermission(string $permission): bool
    {
        return match ($this->role) {
            self::ROLE_PRINCIPAL_ADMIN, self::ROLE_SECONDARY_ADMIN => true,
            self::ROLE_ADMIN => in_array($permission, $this->permissions ?? [], true),
            default => false,
        };
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function levelAdviserAppointments(): HasMany
    {
        return $this->hasMany(LevelAdviserAppointment::class);
    }

    /** Current (not removed) Level Adviser appointment, expired or not. */
    public function currentAdviserAppointment(): ?LevelAdviserAppointment
    {
        return $this->levelAdviserAppointments()->whereNull('removed_at')->latest('starts_on')->first();
    }

    public function homeRoute(): string
    {
        return match (true) {
            $this->isAdmin() => 'admin.dashboard',
            $this->role === self::ROLE_LECTURER => 'staff.dashboard',
            default => 'student.dashboard',
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GraduationRequirement extends Model
{
    /** Defaults used until the HoD or EO saves their own values. Transfer follows Regular. */
    public const DEFAULTS = [
        StudentProfile::ENTRY_REGULAR => [120, 120],
        StudentProfile::ENTRY_DIRECT => [90, 90],
        StudentProfile::ENTRY_TRANSFER => [120, 120],
    ];

    protected $fillable = ['entry_mode', 'min_credits_registered', 'min_credits_passed', 'set_by'];

    /** @return array{registered: int, passed: int} */
    public static function forMode(string $mode): array
    {
        $row = static::where('entry_mode', $mode)->first();
        [$passed, $registered] = [self::DEFAULTS[$mode][0] ?? 120, self::DEFAULTS[$mode][1] ?? 120];

        return [
            'registered' => $row?->min_credits_registered ?? $registered,
            'passed' => $row?->min_credits_passed ?? $passed,
        ];
    }
}

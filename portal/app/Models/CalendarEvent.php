<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    /** Events that control the portal, by key. */
    public const WINDOWS = [
        'registration_1' => 'First semester course registration',
        'registration_2' => 'Second semester course registration',
        'results_1' => 'First semester result upload by lecturers',
        'results_2' => 'Second semester result upload by lecturers',
        'semester_2_start' => 'Second semester begins (the portal switches to it)',
    ];

    public const CATEGORIES = [
        'session' => 'Session',
        'registration' => 'Registration',
        'lectures' => 'Lectures',
        'exams' => 'Examinations',
        'results' => 'Results',
        'senate' => 'Senate and boards',
        'break' => 'Break',
        'faculty' => 'Faculty activities',
        'holiday' => 'Public holiday',
        'other' => 'Other',
    ];

    protected $fillable = ['academic_session_id', 'key', 'title', 'category', 'starts_on', 'ends_on', 'override', 'notes', 'reminded_at'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'reminded_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function lastDay(): CarbonInterface
    {
        return $this->ends_on ?? $this->starts_on;
    }

    /** open, upcoming or closed on the given day; the HoD's override wins over the dates. */
    public function stateOn(CarbonInterface $day): string
    {
        if ($this->override === self::OPEN) {
            return 'open';
        }
        if ($this->override === self::CLOSED) {
            return 'closed';
        }
        $day = $day->copy()->startOfDay();

        return match (true) {
            $day->lt($this->starts_on) => 'upcoming',
            $day->gt($this->lastDay()) => 'closed',
            default => 'open',
        };
    }

    public function dateLabel(): string
    {
        $a = $this->starts_on;
        $b = $this->ends_on;
        if (! $b || $b->equalTo($a)) {
            return $a->format('D j M Y');
        }

        return $a->format($a->year === $b->year ? 'D j M' : 'D j M Y').' to '.$b->format('D j M Y');
    }
}

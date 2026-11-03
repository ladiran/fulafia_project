<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\CourseOffering;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\PortalMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The academic calendar. Events with a key open and close parts of the portal: course registration,
 * result upload by lecturers, and the switch to the second semester. A session without such an event is
 * not restricted, so the portal keeps working before a calendar is loaded. The HoD edits the calendar and
 * can force any window open or closed.
 */
class CalendarService
{
    /** Days before a window closes when reminders go out. */
    public const REMIND_DAYS = 3;

    public function today(): CarbonInterface
    {
        // The date on the department's clock, as midnight in the app's timezone, so it compares cleanly with the date columns.
        return Carbon::parse(Carbon::now(config('portal.timezone'))->toDateString());
    }

    public function window(AcademicSession $session, string $key): ?CalendarEvent
    {
        return CalendarEvent::where('academic_session_id', $session->id)->where('key', $key)->first();
    }

    /** Why a student cannot register right now because of the calendar, or null. */
    public function registrationBlocked(AcademicSession $session): ?string
    {
        $window = $this->window($session, 'registration_'.$session->current_semester);

        return $window ? $this->blockMessage($window, 'Course registration') : null;
    }

    /** Why a lecturer cannot work on this sheet right now because of the calendar, or null. */
    public function resultsBlocked(CourseOffering $offering): ?string
    {
        $offering->loadMissing('course', 'session');
        $window = $this->window($offering->session, 'results_'.$offering->course->semester);

        return $window ? $this->blockMessage($window, 'Result upload') : null;
    }

    private function blockMessage(CalendarEvent $window, string $what): ?string
    {
        return match ($window->stateOn($this->today())) {
            'open' => null,
            'upcoming' => "{$what} has not opened yet. It opens on ".$window->starts_on->format('l j F Y').'.',
            default => "{$what} closed on ".$window->lastDay()->format('l j F Y').'. Ask the HoD if you need it opened again.',
        };
    }

    /**
     * The controlling windows of a session with their state today, for notices on the portal.
     *
     * @return array<string, array{title: string, label: string, state: string}>
     */
    public function windows(AcademicSession $session): array
    {
        $out = [];
        foreach (CalendarEvent::where('academic_session_id', $session->id)->whereNotNull('key')->orderBy('starts_on')->get() as $event) {
            $out[$event->key] = ['title' => CalendarEvent::WINDOWS[$event->key] ?? $event->title, 'label' => $event->dateLabel(), 'state' => $event->stateOn($this->today())];
        }

        return $out;
    }

    /** Daily: move a session to its second semester once the calendar says it has begun. */
    public function advanceSemesters(): int
    {
        $moved = 0;
        $session = AcademicSession::current();
        $start = $session ? $this->window($session, 'semester_2_start') : null;
        if ($session && $start && (int) $session->current_semester === 1 && $this->today()->gte($start->starts_on)) {
            $session->update(['current_semester' => 2]);
            AuditLog::record('calendar.semester_switched', ['session' => $session->name, 'semester' => 2]);
            $moved++;
        }

        return $moved;
    }

    /** Daily: warn the people concerned when a window is about to close. Each window is announced once. */
    public function sendReminders(): int
    {
        $session = AcademicSession::current();
        if (! $session) {
            return 0;
        }
        $today = $this->today();
        $sent = 0;

        $due = CalendarEvent::where('academic_session_id', $session->id)->whereIn('key', ['registration_1', 'registration_2', 'results_1', 'results_2'])
            ->whereNull('reminded_at')->get()
            ->filter(fn (CalendarEvent $e) => $e->override === null && $e->stateOn($today) === 'open'
                && $today->diffInDays($e->lastDay(), false) <= self::REMIND_DAYS);

        foreach ($due as $event) {
            $when = $event->lastDay()->format('l j F Y');
            $semester = (int) substr($event->key, -1);
            if (str_starts_with($event->key, 'registration')) {
                $users = User::whereIn('id', StudentProfile::where('status', 'active')->pluck('user_id'))->get();
                $title = 'Course registration closes soon';
                $text = "Course registration closes on {$when}. Register your courses before then.";
            } else {
                $users = User::whereIn('id', CourseOffering::where('academic_session_id', $session->id)->where('status', CourseOffering::DRAFT)
                    ->whereNotNull('lecturer_id')->whereHas('course', fn ($c) => $c->where('semester', $semester))->pluck('lecturer_id'))->get();
                $title = 'Result upload closes soon';
                $text = "Result upload closes on {$when}. Finish and submit your result sheets before then.";
            }
            $users->each(fn (User $u) => $u->notify(new PortalMessage($title, $text)));
            User::where('role', User::ROLE_PRINCIPAL_ADMIN)->where('is_active', true)->get()
                ->each(fn (User $u) => $u->notify(new PortalMessage($title, "{$event->title} ends on {$when}. {$users->count()} person(s) were reminded.")));
            $event->forceFill(['reminded_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }

    /** Load the official 2026/2027 calendar into a session that has no events yet. */
    public function loadOfficial(AcademicSession $session): int
    {
        $file = database_path('data/calendar_'.str_replace('/', '_', $session->name).'.php');
        abort_unless(is_file($file), 422, "There is no official calendar on file for {$session->name}.");

        foreach (require $file as [$title, $category, $start, $end, $key, $note]) {
            CalendarEvent::create([
                'academic_session_id' => $session->id, 'title' => $title, 'category' => $category,
                'starts_on' => $start, 'ends_on' => $end, 'key' => $key, 'notes' => $note,
            ]);
        }

        return CalendarEvent::where('academic_session_id', $session->id)->count();
    }

    /** Start a session's calendar from another one, moving every date forward by whole weeks (364 days per year). */
    public function copy(AcademicSession $from, AcademicSession $to): int
    {
        $years = (int) substr($to->name, 0, 4) - (int) substr($from->name, 0, 4);
        $days = 364 * $years;
        $count = 0;
        foreach (CalendarEvent::where('academic_session_id', $from->id)->get() as $e) {
            CalendarEvent::create([
                'academic_session_id' => $to->id, 'key' => $e->key, 'title' => $e->title, 'category' => $e->category,
                'starts_on' => $e->starts_on->copy()->addDays($days), 'ends_on' => $e->ends_on?->copy()->addDays($days), 'notes' => $e->notes,
            ]);
            $count++;
        }

        return $count;
    }
}

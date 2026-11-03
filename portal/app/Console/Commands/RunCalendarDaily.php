<?php

namespace App\Console\Commands;

use App\Services\CalendarService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('calendar:daily')]
#[Description('Switch to the second semester when the calendar says so, and remind people before a window closes')]
class RunCalendarDaily extends Command
{
    public function handle(CalendarService $calendar): int
    {
        $this->info('Semesters switched: '.$calendar->advanceSemesters());
        $this->info('Closing-soon reminders sent: '.$calendar->sendReminders());

        return self::SUCCESS;
    }
}

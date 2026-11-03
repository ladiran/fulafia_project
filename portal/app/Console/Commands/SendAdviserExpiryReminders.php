<?php

namespace App\Console\Commands;

use App\Models\LevelAdviserAppointment;
use App\Models\User;
use App\Notifications\AdviserTenureEnding;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('advisers:send-expiry-reminders')]
#[Description('Notify each Level Adviser and the HoD when a tenure is two months from ending')]
class SendAdviserExpiryReminders extends Command
{
    public function handle(): int
    {
        $hods = User::where('role', User::ROLE_PRINCIPAL_ADMIN)->where('is_active', true)->get();
        $sent = 0;

        LevelAdviserAppointment::dueForReminder()
            ->whereNull('reminder_sent_at')
            ->with('user')
            ->each(function (LevelAdviserAppointment $appointment) use ($hods, &$sent) {
                $appointment->user->notify(new AdviserTenureEnding($appointment, forHod: false));
                $hods->each(fn (User $hod) => $hod->notify(new AdviserTenureEnding($appointment, forHod: true)));

                $appointment->forceFill(['reminder_sent_at' => now()])->save();
                $sent++;
            });

        $this->info("Reminders sent for {$sent} appointment(s).");

        return self::SUCCESS;
    }
}

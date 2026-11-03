<?php

namespace App\Notifications;

use App\Models\LevelAdviserAppointment;
use Illuminate\Notifications\Notification;

class AdviserTenureEnding extends Notification
{
    public function __construct(public LevelAdviserAppointment $appointment, public bool $forHod) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $a = $this->appointment;
        $who = $this->forHod ? $a->user->name.' (Level Adviser, '.$a->level.'L)' : 'Your tenure as '.$a->level.'L Level Adviser';

        return [
            'title' => 'Level Adviser tenure ending',
            'message' => $who.' ends on '.$a->ends_on->format('j M Y').'.',
            'appointment_id' => $a->id,
        ];
    }
}

<?php

namespace App\Notifications;

use App\Models\CourseOffering;
use Illuminate\Notifications\Notification;

class ResultsPublished extends Notification
{
    public function __construct(public CourseOffering $offering) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Results published',
            'message' => 'Your result for '.$this->offering->course->code.' ('.$this->offering->session->name.') has been published.',
        ];
    }
}

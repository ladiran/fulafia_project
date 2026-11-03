<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** A plain in-portal message (shown in the notifications box on every page). */
class PortalMessage extends Notification
{
    public function __construct(public string $title, public string $message) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message];
    }
}

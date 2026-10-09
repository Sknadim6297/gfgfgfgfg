<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SupportTicketReplied extends Notification
{
    use Queueable;

    public function __construct(private readonly SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Support replied to ticket '.$this->ticket->ticket_number,
            'ticket_number' => $this->ticket->ticket_number,
            'url' => route('support.tickets.show', $this->ticket->ticket_number),
        ];
    }
}

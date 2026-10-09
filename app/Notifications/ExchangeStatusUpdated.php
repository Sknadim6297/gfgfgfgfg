<?php

namespace App\Notifications;

use App\Models\ExchangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ExchangeStatusUpdated extends Notification
{
    use Queueable;

    public function __construct(private readonly ExchangeRequest $exchangeRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $reference = $this->exchangeRequest->request_reference ?? 'exchange request';

        return [
            'message' => 'Your sell request '.$reference.' is now '.$this->exchangeRequest->status.'.',
            'request_reference' => $this->exchangeRequest->request_reference,
            'status' => $this->exchangeRequest->status,
        ];
    }
}

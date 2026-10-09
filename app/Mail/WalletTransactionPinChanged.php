<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WalletTransactionPinChanged extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $action) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Quick PayMoney account security update');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.wallet-transaction-pin-changed');
    }
}

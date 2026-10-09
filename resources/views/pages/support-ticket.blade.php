@extends('layouts.app')

@section('title', 'Quick PayMoney | Support Ticket')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/contact.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/support.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">
        @include('partials.home-header')

        <main class="support-area">
            <div class="support-banner">
                <div class="support-icon"><i class="bi bi-headset" aria-hidden="true"></i></div>
                <h1>24/7 Premium Support</h1>
                <p>We're here whenever you need us.</p>
            </div>

            <section class="support-card">
                <div class="support-heading">
                    <h2>{{ $ticket->ticket_number }}</h2>
                    <p>{{ $ticket->subject }}</p>
                </div>
                <dl class="support-ticket-details">
                    <div><dt>Category</dt><dd>{{ $ticket->category }}</dd></div>
                    <div><dt>Status</dt><dd>{{ str($ticket->status)->replace('_', ' ')->title() }}</dd></div>
                    <div><dt>Created</dt><dd>{{ $ticket->created_at->format('M j, Y g:i A') }}</dd></div>
                </dl>

                <div class="support-conversation" aria-label="Ticket conversation">
                    @foreach ($ticket->messages as $message)
                        <article class="support-message {{ $message->sender_type === 'admin' ? 'support-message-admin' : '' }}">
                            <strong>{{ $message->sender_type === 'admin' ? 'Quick PayMoney Support' : 'You' }}</strong>
                            <time datetime="{{ $message->created_at->toIso8601String() }}">
                                {{ $message->created_at->format('M j, Y g:i A') }}
                            </time>
                            <p>{{ $message->message }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            @if ($ticket->status !== 'closed')
                <section class="support-card">
                    <div class="support-heading">
                        <h2>Reply to this ticket</h2>
                        <p>Send a message to the Support team.</p>
                    </div>
                    <form method="POST" action="{{ route('support.tickets.reply', $ticket->ticket_number) }}">
                        @csrf
                        <div class="input-group-custom">
                            <textarea class="form-control-custom" id="support-reply" name="message" rows="5"
                                maxlength="5000" placeholder="Write your reply..." required>{{ old('message') }}</textarea>
                            @error('message') <span class="support-field-error">{{ $message }}</span> @enderror
                        </div>
                        <button class="submit-btn" type="submit">
                            <i class="bi bi-send-fill" aria-hidden="true"></i>
                            Send Reply
                        </button>
                    </form>
                </section>
            @endif

            <a class="support-back-link" href="{{ route('contact') }}">Back to Support</a>
        </main>

        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection
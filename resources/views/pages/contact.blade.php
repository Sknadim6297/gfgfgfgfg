@extends('layouts.app')

@section('title', 'Quick PayMoney | Premium Support')

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
                    <h2>Let's Solve Your Problem</h2>
                    <p>Tell us how we can help you.</p>
                </div>

                <form action="{{ route('support.tickets.store') }}" method="POST">
                    @csrf

                    @guest
                        <div class="input-group-custom">
                            <input class="form-control-custom" id="support-name" name="name" type="text"
                                placeholder="Full Name" value="{{ old('name') }}" maxlength="120"
                                autocomplete="name" required>
                            @error('name') <span class="support-field-error">{{ $message }}</span> @enderror
                        </div>

                        <div class="input-group-custom">
                            <input class="form-control-custom" id="support-email" name="email" type="email"
                                placeholder="Email Address" value="{{ old('email') }}" maxlength="255"
                                autocomplete="email" required>
                            @error('email') <span class="support-field-error">{{ $message }}</span> @enderror
                        </div>
                    @endguest

                    <div class="input-group-custom">
                        <input class="form-control-custom" id="support-subject" name="subject" type="text"
                            placeholder="Subject" value="{{ old('subject') }}" maxlength="150" required>
                        @error('subject') <span class="support-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="input-group-custom">
                        <select class="form-control-custom" id="support-category" name="category" required>
                            <option value="" disabled @selected(!old('category'))>Choose a category</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category }}" @selected(old('category') === $category)>{{ $category }}</option>
                            @endforeach
                        </select>
                        @error('category') <span class="support-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="input-group-custom">
                        <textarea class="form-control-custom" id="support-message" name="message" rows="5"
                            placeholder="Describe your problem..." maxlength="5000" required>{{ old('message') }}</textarea>
                        @error('message') <span class="support-field-error">{{ $message }}</span> @enderror
                    </div>

                    <button class="submit-btn" type="submit">
                        <i class="bi bi-send-fill" aria-hidden="true"></i>
                        Submit Request
                    </button>
                </form>
            </section>

            @if ($customer)
                <section class="support-card support-ticket-list">
                    <div class="support-heading">
                        <h2>My Support Tickets</h2>
                    </div>

                    @foreach ($replyNotifications as $notification)
                        <a class="support-notification-link"
                            href="{{ route('support.tickets.show', $notification->data['ticket_number']) }}">
                            {{ $notification->data['message'] ?? 'You have a support reply.' }}
                        </a>
                    @endforeach

                    @forelse ($tickets as $ticket)
                        <article class="support-ticket-row">
                            <div class="support-ticket-info">
                                <strong>{{ $ticket->ticket_number }}</strong>
                                <span>{{ $ticket->subject }}</span>
                                <small>
                                    {{ $ticket->category }} · {{ str($ticket->status)->replace('_', ' ')->title() }} ·
                                    {{ $ticket->updated_at->format('M j, Y g:i A') }}
                                </small>
                            </div>
                            <a class="support-ticket-view" href="{{ route('support.tickets.show', $ticket->ticket_number) }}">
                                View
                            </a>
                        </article>
                    @empty
                        <p class="support-empty-state">No support tickets yet.</p>
                    @endforelse

                    @if ($tickets->hasPages())
                        <div class="support-pagination">{{ $tickets->links() }}</div>
                    @endif
                </section>
            @endif
        </main>

        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection

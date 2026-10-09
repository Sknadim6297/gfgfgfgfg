@extends('layouts.app')

@section('title', 'Quick PayMoney | Bank Details')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/portal.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/profile-dashboard.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection

@section('content')
    @php
        $hasBankDetails = $user->hasCompleteBankDetails();
        $showBankEditForm = ! $hasBankDetails || $errors->any();
    @endphp
    <div class="main-wrapper">
        @include('partials.home-header')
        <main class="content-area app-shell profile-dashboard">
            @include('partials.profile-hero', ['user' => $user])
            <a class="profile-back-link" href="{{ route('profile') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Profile</a>
            <section class="profile-content-card">
                <div class="profile-content-heading">
                    <span class="profile-content-icon"><i class="bi bi-bank" aria-hidden="true"></i></span>
                    <div><h2>Bank Details</h2><p>Manage your payout bank information.</p></div>
                    <span class="profile-bank-status is-{{ match ($bankVerificationStatus) {
                        'not_submitted' => 'not-added',
                        'pending' => 'pending',
                        'verified' => 'verified',
                        'rejected' => 'rejected',
                    } }}">{{ match ($bankVerificationStatus) {
                        'pending' => 'Pending Verification',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                        default => 'Not Added',
                    } }}</span>
                </div>
                @if ($hasBankDetails)
                    <section class="profile-bank-summary" aria-labelledby="saved-bank-account-heading">
                        <h3 id="saved-bank-account-heading">Saved Bank Account</h3>
                        <dl class="profile-bank-summary-list">
                            <div><dt>Account Holder</dt><dd>{{ $user->account_holder_name }}</dd></div>
                            <div><dt>Bank Name</dt><dd>{{ $user->bank_name }}</dd></div>
                            <div><dt>Account Number</dt><dd>{{ $user->maskedBankAccountNumber() }}</dd></div>
                            <div><dt>IFSC Code</dt><dd>{{ $user->ifsc_code }}</dd></div>
                            <div><dt>Branch</dt><dd>{{ $user->branch_name }}</dd></div>
                            <div><dt>Account Type</dt><dd>{{ $user->account_type }}</dd></div>
                            <div><dt>Verification Status</dt><dd>{{ match ($bankVerificationStatus) {
                                'pending' => 'Pending Verification',
                                'verified' => 'Verified',
                                'rejected' => 'Rejected',
                                default => 'Not Submitted',
                            } }}</dd></div>
                            @if ($bankVerificationStatus === 'rejected' && $user->bank_verification_reason)
                                <div><dt>Reason</dt><dd>{{ $user->bank_verification_reason }}</dd></div>
                            @endif
                            @if ($user->bank_submitted_at)
                                <div><dt>Submitted / Updated</dt><dd>{{ $user->bank_submitted_at->format('M j, Y · H:i') }}</dd></div>
                            @endif
                        </dl>
                    </section>
                    <button class="profile-save-button profile-bank-edit-toggle" type="button"
                        aria-controls="bank-details-edit-form" aria-expanded="{{ $showBankEditForm ? 'true' : 'false' }}"
                        data-bank-edit-open>
                        <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit Bank Details
                    </button>
                @endif
                <div id="bank-details-edit-form" @if (! $showBankEditForm) hidden aria-hidden="true" @endif>
                    <h3 class="profile-bank-form-heading">{{ $hasBankDetails ? 'Edit Bank Details' : 'Add Bank Details' }}</h3>
                    <form class="profile-form" method="POST" action="{{ route('profile.update') }}" autocomplete="off">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="section" value="bank">
                    <div class="profile-form-grid">
                        <div class="profile-input-group"><label for="account-holder-name">Account Holder Name</label><input class="profile-form-control" id="account-holder-name" name="account_holder_name" value="{{ old('account_holder_name', $user->account_holder_name) }}" maxlength="120" autocomplete="off" required>@error('account_holder_name')<span class="profile-field-error">{{ $message }}</span>@enderror</div>
                        <div class="profile-input-group"><label for="bank-name">Bank Name</label><input class="profile-form-control" id="bank-name" name="bank_name" value="{{ old('bank_name', $user->bank_name) }}" maxlength="120" autocomplete="off" required>@error('bank_name')<span class="profile-field-error">{{ $message }}</span>@enderror</div>
                        <div class="profile-input-group"><label for="account-number">Account Number</label><input class="profile-form-control" id="account-number" name="account_number" type="password" inputmode="numeric" minlength="6" maxlength="20" autocomplete="new-password" required>@error('account_number')<span class="profile-field-error">{{ $message }}</span>@enderror</div>
                        <div class="profile-input-group"><label for="ifsc-code">IFSC Code</label><input class="profile-form-control profile-ifsc-input" id="ifsc-code" name="ifsc_code" value="{{ old('ifsc_code', $user->ifsc_code) }}" minlength="11" maxlength="11" autocapitalize="characters" autocomplete="off" required>@error('ifsc_code')<span class="profile-field-error">{{ $message }}</span>@enderror</div>
                        <div class="profile-input-group"><label for="branch-name">Branch Name</label><input class="profile-form-control" id="branch-name" name="branch_name" value="{{ old('branch_name', $user->branch_name) }}" maxlength="120" autocomplete="off" required>@error('branch_name')<span class="profile-field-error">{{ $message }}</span>@enderror</div>
                        <div class="profile-input-group"><label for="account-type">Account Type</label><select class="profile-form-control" id="account-type" name="account_type" required><option value="">Select account type</option>@foreach (['Savings', 'Current'] as $accountType)<option value="{{ $accountType }}" @selected(old('account_type', $user->account_type) === $accountType)>{{ $accountType }}</option>@endforeach</select>@error('account_type')<span class="profile-field-error">{{ $message }}</span>@enderror</div>
                    </div>
                        <div class="profile-form-actions profile-bank-edit-actions">
                            <button class="profile-save-button" type="submit"><i class="bi bi-check-circle" aria-hidden="true"></i> {{ $hasBankDetails ? 'Save Changes' : 'Save Bank Details' }}</button>
                            @if ($hasBankDetails)
                                <button class="profile-save-button profile-bank-edit-cancel" type="button" aria-controls="bank-details-edit-form" data-bank-edit-cancel>Cancel</button>
                            @endif
                        </div>
                    </form>
                </div>
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard', 'appShell' => true])
    </div>
@endsection

@section('scripts')
    @include('partials.profile-copy-script')
    <script>
        (() => {
            const form = document.getElementById('bank-details-edit-form');
            const openButton = document.querySelector('[data-bank-edit-open]');
            const cancelButton = document.querySelector('[data-bank-edit-cancel]');

            if (!form || !openButton || !cancelButton) return;

            openButton.addEventListener('click', () => {
                form.hidden = false;
                form.removeAttribute('aria-hidden');
                openButton.setAttribute('aria-expanded', 'true');
                form.querySelector('input:not([type="hidden"])')?.focus();
            });

            cancelButton.addEventListener('click', () => {
                form.querySelector('form').reset();
                form.hidden = true;
                form.setAttribute('aria-hidden', 'true');
                openButton.setAttribute('aria-expanded', 'false');
                openButton.focus();
            });
        })();
    </script>
@endsection

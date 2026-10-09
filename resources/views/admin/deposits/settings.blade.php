@extends('layouts.admin')

@section('title', 'Quick PayMoney | Deposit Settings')

@section('styles')
    @parent
    <link href="{{ asset('assets/css/deposit.css') }}" rel="stylesheet">
@endsection

@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">PAYMENT CONFIGURATION</span><h1>Deposit settings</h1><p>Manage customer-facing payment instructions and securely stored QR image.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.deposits.index') }}"><i class="bi bi-inbox" aria-hidden="true"></i> Deposit requests</a>
    </div>

    <div class="admin-detail-grid">
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">PAYMENT DETAILS</span><h2>Customer instructions</h2><p>Changes are recorded in the administrative audit log.</p></div></div>
            <form class="admin-form" id="deposit-settings-form" method="POST" action="{{ route('admin.deposit-settings.update') }}" enctype="multipart/form-data">
                @csrf @method('PUT')
                <label for="recipient_name">Payment recipient name</label>
                <input class="portal-input" id="recipient_name" name="recipient_name" value="{{ old('recipient_name', $settings?->recipient_name) }}" maxlength="120">
                @error('recipient_name')<span class="admin-field-error">{{ $message }}</span>@enderror

                <label for="instructions">Payment instructions</label>
                <textarea class="portal-input" id="instructions" name="instructions" rows="6" maxlength="5000">{{ old('instructions', $settings?->instructions) }}</textarea>
                @error('instructions')<span class="admin-field-error">{{ $message }}</span>@enderror

                <label for="qr_image">Payment QR image <span>(PNG, JPG, or WebP; up to 4 MB)</span></label>
                <input class="portal-input" id="qr_image" name="qr_image" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                @error('qr_image')<span class="admin-field-error">{{ $message }}</span>@enderror
                @if ($qrImageAvailable)
                    <div class="deposit-admin-qr-preview">
                        <img src="{{ route('admin.deposit-settings.qr') }}" alt="Current payment QR code">
                        <label class="deposit-remove-qr"><input type="checkbox" name="remove_qr" value="1"> Remove current QR image</label>
                    </div>
                @elseif ($settings?->qr_path)
                    <div class="admin-inline-notice"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p>The saved QR image is missing or outside the managed QR directory. Upload a replacement to restore customer payments.</p></div>
                @endif
                <button class="admin-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save deposit settings</button>
            </form>
            @if ($settings)
                <form class="admin-delete-settings-form" method="POST" action="{{ route('admin.deposit-settings.destroy') }}" data-confirm="This removes the active payment details and QR image. Existing deposit requests and their audit history will remain unchanged." data-confirm-title="Delete payment settings?" data-confirm-button="Delete settings">
                    @csrf
                    @method('DELETE')
                    <button class="admin-button admin-button-danger" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> Delete payment settings</button>
                </form>
            @endif
        </section>

        <aside class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">CUSTOMER VIEW</span><h2>Configuration status</h2></div></div>
            <dl class="admin-detail-list">
                <div><dt>QR code</dt><dd>{{ $qrImageAvailable ? 'Configured' : ($settings?->qr_path ? 'Unavailable' : 'Not configured') }}</dd></div>
                <div><dt>Recipient</dt><dd>{{ $settings?->recipient_name ?: 'Not provided' }}</dd></div>
                <div><dt>Instructions</dt><dd>{{ filled($settings?->instructions) ? 'Configured' : 'Not provided' }}</dd></div>
                <div><dt>Last updated by</dt><dd>{{ $settings?->updatedBy?->name ?? 'Not updated' }}</dd></div>
                @if ($settings?->updated_at)<div><dt>Last updated</dt><dd>{{ $settings->updated_at->format('M j, Y · H:i') }}</dd></div>@endif
            </dl>
            @unless ($qrImageAvailable && filled($settings?->recipient_name) && filled($settings?->instructions))
                <div class="admin-inline-notice"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p>Customer deposit requests stay unavailable until a QR image, recipient name, and payment instructions are all configured.</p></div>
            @endunless
            <div class="admin-inline-notice"><i class="bi bi-lock" aria-hidden="true"></i><p>Images are stored privately and only served to authenticated customers and authorized admins.</p></div>
        </aside>
    </div>

    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">AUDIT TRAIL</span><h2>Deposit settings history</h2></div></div>
        <div class="admin-activity-list">
            @forelse ($history as $entry)
                <article class="admin-activity-item"><span class="admin-activity-icon"><i class="bi bi-qr-code" aria-hidden="true"></i></span><div class="admin-activity-copy"><strong>{{ str_replace('_', ' ', $entry->event) }}</strong><span>{{ $entry->actor?->name ?? 'Administrator' }} · {{ $entry->created_at->format('M j, Y · H:i') }}</span></div></article>
            @empty<div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No settings changes recorded</strong><p>Future updates will be recorded here.</p></div>@endforelse
        </div>
    </section>
@endsection

@section('scripts')
    <script>
        (() => {
            const form = document.getElementById('deposit-settings-form');
            const removeQr = form?.querySelector('[name="remove_qr"]');
            if (!form || !removeQr) return;

            form.addEventListener('submit', () => {
                if (removeQr.checked) {
                    form.dataset.confirm = 'Remove the active payment QR? Customer deposit submissions will remain unavailable until a replacement QR is uploaded.';
                    form.dataset.confirmTitle = 'Remove payment QR?';
                    form.dataset.confirmButton = 'Remove QR';
                } else {
                    delete form.dataset.confirm;
                    delete form.dataset.confirmTitle;
                    delete form.dataset.confirmButton;
                }
            });
        })();
    </script>
@endsection

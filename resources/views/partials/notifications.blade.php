@php
    $type = collect(['success', 'error', 'warning', 'info'])
        ->first(fn (string $type) => session()->has($type));

    $notice = $type ? ['type' => $type, 'message' => (string) session($type)] : (
        session()->has('status') ? ['type' => 'success', 'message' => (string) session('status')] : (
            session()->has('rate_notice') ? ['type' => 'warning', 'message' => (string) session('rate_notice')] : (
                session()->has('sell_request_submitted')
                    ? ['type' => 'success', 'message' => 'Your sell request has been submitted and is awaiting review.']
                    : null
            )
        )
    );

    $payload = [
        'notice' => $notice,
        'errors' => $errors->all(),
    ];
@endphp
<script id="quickpay-notifications" type="application/json">{!! json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

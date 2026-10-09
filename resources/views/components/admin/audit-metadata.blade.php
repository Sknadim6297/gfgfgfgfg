@props(['metadata' => [], 'event'])

@php
    $presenter = app(\App\Support\AdminAuditPresenter::class);
    $metadata = is_array($metadata) ? $metadata : [];
    $displayable = collect($metadata)->filter(fn ($value, $key) => $presenter->shouldDisplay((string) $key, $value));
@endphp

@if ($displayable->isNotEmpty())
    <dl class="admin-audit-details">
        @foreach ($displayable as $key => $value)
            <div>
                <dt>{{ $presenter->label($key) }}</dt>
                <dd>
                    @if (is_array($value))
                        <x-admin.audit-metadata :metadata="$value" :event="$event" />
                    @else
                        {{ $presenter->value($key, $value, $event) }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif

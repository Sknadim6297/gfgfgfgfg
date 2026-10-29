@extends('layouts.admin')

@section('title', $notice->exists ? 'Quick PayMoney | Edit Notice' : 'Quick PayMoney | Create Notice')

@section('styles')
    @parent
    <link href="{{ asset('assets/css/member-notices.css') }}" rel="stylesheet">
@endsection

@section('admin-content')
    @php
        $publishValue = old('publish_at', $notice->publish_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i'));
        $expiryValue = old('expires_at', $notice->expires_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i'));
    @endphp
    <div class="admin-page-heading">
        <div>
            <span class="admin-eyebrow">MEMBER COMMUNICATION</span>
            <h1>{{ $notice->exists ? 'Edit notice' : 'Create notice' }}</h1>
            <p>Preview the announcement before members can see it. Draft notices stay private.</p>
        </div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.notices.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> All notices</a>
    </div>

    <section class="admin-panel">
        <form class="admin-form" id="member-notice-form" method="POST" action="{{ $notice->exists ? route('admin.notices.update', $notice) : route('admin.notices.store') }}">
            @csrf
            @if ($notice->exists) @method('PUT') @endif

            <label for="notice-title">Title</label>
            <input class="portal-input" id="notice-title" name="title" value="{{ old('title', $notice->title) }}" maxlength="160" required>
            @error('title')<span class="admin-field-error">{{ $message }}</span>@enderror

            <label for="notice-description">Description</label>
            <textarea class="portal-input" id="notice-description" name="description" rows="8" maxlength="5000" required>{{ old('description', $notice->description) }}</textarea>
            @error('description')<span class="admin-field-error">{{ $message }}</span>@enderror

            <div class="admin-form-columns">
                <div>
                    <label for="notice-type">Notice type</label>
                    <select class="portal-input" id="notice-type" name="type">
                        @foreach (\App\Models\MemberNotice::TYPES as $type)
                            <option value="{{ $type }}" @selected(old('type', $notice->type) === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                    @error('type')<span class="admin-field-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="notice-priority">Priority</label>
                    <select class="portal-input" id="notice-priority" name="priority">
                        @foreach (\App\Models\MemberNotice::PRIORITIES as $priority)
                            <option value="{{ $priority }}" @selected(old('priority', $notice->priority) === $priority)>{{ ucfirst($priority) }}</option>
                        @endforeach
                    </select>
                    @error('priority')<span class="admin-field-error">{{ $message }}</span>@enderror
                </div>
            </div>

            <label for="notice-status">Notice status</label>
            <select class="portal-input" id="notice-status" name="status">
                @foreach (\App\Models\MemberNotice::STATUSES as $status)
                    <option value="{{ $status }}" @selected(old('status', $notice->status) === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            @error('status')<span class="admin-field-error">{{ $message }}</span>@enderror

            <div class="admin-form-columns">
                <div>
                    <label for="notice-publish-at">Publish date <span>(optional)</span></label>
                    <input class="portal-input" id="notice-publish-at" name="publish_at" type="datetime-local" value="{{ $publishValue }}">
                    @error('publish_at')<span class="admin-field-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="notice-expires-at">Expiry date <span>(optional)</span></label>
                    <input class="portal-input" id="notice-expires-at" name="expires_at" type="datetime-local" value="{{ $expiryValue }}">
                    @error('expires_at')<span class="admin-field-error">{{ $message }}</span>@enderror
                </div>
            </div>

            <label>
                <input type="hidden" name="popup_enabled" value="0">
                <input type="checkbox" name="popup_enabled" value="1" @checked(filter_var(old('popup_enabled', $notice->popup_enabled), FILTER_VALIDATE_BOOLEAN))> Show this notice in the member sign-in popup
            </label>
            @error('popup_enabled')<span class="admin-field-error">{{ $message }}</span>@enderror
            <p class="admin-form-help">Only a published notice inside its publish and expiry window can reach members. Popup notices are shown one at a time after each new sign-in.</p>

            <div class="admin-inline-actions">
                <button class="admin-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save notice</button>
                <button class="admin-button admin-button-secondary" id="notice-preview-open" type="button"><i class="bi bi-eye" aria-hidden="true"></i> Preview notice</button>
            </div>
        </form>
    </section>

    <dialog class="member-notice-dialog" id="admin-notice-preview" aria-labelledby="admin-notice-preview-title">
        <div class="member-notice-card">
            <button class="member-notice-close" type="button" data-preview-close aria-label="Close preview"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            <div class="member-notice-icon" id="admin-notice-preview-icon" aria-hidden="true"><i class="bi bi-info-circle"></i></div>
            <p class="member-notice-kicker" id="admin-notice-preview-kicker">Information</p>
            <h2 id="admin-notice-preview-title" tabindex="-1">Notice title</h2>
            <p class="member-notice-copy" id="admin-notice-preview-copy"></p>
            <p class="member-notice-date" id="admin-notice-preview-date"></p>
            <button class="member-notice-action" type="button" data-preview-close>Got it</button>
        </div>
    </dialog>
@endsection

@section('scripts')
    <script>
        (() => {
            const form = document.getElementById('member-notice-form');
            const dialog = document.getElementById('admin-notice-preview');
            const open = document.getElementById('notice-preview-open');
            if (!form || !dialog || !open) return;

            const icons = {
                information: 'bi-info-circle',
                important: 'bi-megaphone',
                warning: 'bi-exclamation-triangle',
                maintenance: 'bi-tools',
            };
            const labels = {
                information: 'Information',
                important: 'Important',
                warning: 'Warning',
                maintenance: 'Maintenance',
            };

            const fill = () => {
                const type = form.type.value || 'information';
                const title = form.title.value.trim() || 'Notice title';
                const description = form.description.value.trim() || 'Notice description';
                const publishAt = form.publish_at.value;
                const icon = document.getElementById('admin-notice-preview-icon');
                const glyph = document.createElement('i');
                glyph.className = `bi ${icons[type] || icons.information}`;
                glyph.setAttribute('aria-hidden', 'true');
                icon.replaceChildren(glyph);
                document.getElementById('admin-notice-preview-kicker').textContent = labels[type] || labels.information;
                document.getElementById('admin-notice-preview-title').textContent = title;
                document.getElementById('admin-notice-preview-copy').textContent = description;
                document.getElementById('admin-notice-preview-date').textContent = publishAt
                    ? `Publishes ${publishAt.replace('T', ' ')}`
                    : 'Publishes when the notice is saved as published';
            };

            open.addEventListener('click', () => {
                fill();
                dialog.showModal();
                document.getElementById('admin-notice-preview-title').focus();
            });
            dialog.querySelectorAll('[data-preview-close]').forEach((button) => {
                button.addEventListener('click', () => dialog.close());
            });
            dialog.addEventListener('cancel', (event) => {
                event.preventDefault();
                dialog.close();
            });
        })();
    </script>
@endsection

@if (($memberNoticePopups ?? collect())->isNotEmpty())
    <link href="{{ asset('assets/css/member-notices.css') }}" rel="stylesheet">
    <dialog class="member-notice-dialog" id="member-notice-dialog" aria-labelledby="member-notice-title" data-csrf="{{ csrf_token() }}">
        <div class="member-notice-card">
            <div class="member-notice-top">
                <div class="member-notice-badge">
                    <div class="member-notice-icon" id="member-notice-icon" aria-hidden="true"><i class="bi bi-info-circle"></i></div>
                    <p class="member-notice-kicker" id="member-notice-kicker"></p>
                </div>
                <button class="member-notice-close" type="button" data-member-notice-dismiss aria-label="Close notice"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <h2 id="member-notice-title"></h2>
            <p class="member-notice-copy" id="member-notice-copy"></p>
            <p class="member-notice-date" id="member-notice-date"></p>
            <p class="member-notice-date" id="member-notice-error" role="alert" hidden></p>
            <button class="member-notice-action" type="button" data-member-notice-dismiss>Got it</button>
        </div>
    </dialog>
    <script type="application/json" id="member-notice-queue">{!! json_encode($memberNoticePopups->map(fn ($notice) => [
        'title' => $notice->title,
        'description' => $notice->description,
        'type' => $notice->typeLabel(),
        'icon' => $notice->iconClass(),
        'published_on' => $notice->publishedOn()->timezone(config('app.timezone'))->format('M j, Y'),
        'dismiss_url' => route('notices.dismiss', $notice),
    ])->values(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    <script>
        (() => {
            const dialog = document.getElementById('member-notice-dialog');
            const queueNode = document.getElementById('member-notice-queue');
            if (!dialog || !queueNode || dialog.dataset.ready === 'true') return;
            dialog.dataset.ready = 'true';
            const notices = JSON.parse(queueNode.textContent);
            let index = 0;
            let dismissing = false;
            const token = dialog.dataset.csrf || '';

            const setIcon = (iconClass) => {
                const icon = document.getElementById('member-notice-icon');
                const glyph = document.createElement('i');
                glyph.className = `bi ${iconClass}`;
                glyph.setAttribute('aria-hidden', 'true');
                icon.replaceChildren(glyph);
            };

            const render = () => {
                const notice = notices[index];
                const error = document.getElementById('member-notice-error');
                error.hidden = true;
                error.textContent = '';
                if (!notice) {
                    if (dialog.open) dialog.close();
                    return;
                }
                setIcon(notice.icon);
                document.getElementById('member-notice-kicker').textContent = notice.type;
                document.getElementById('member-notice-title').textContent = notice.title;
                document.getElementById('member-notice-copy').textContent = notice.description;
                document.getElementById('member-notice-date').textContent = `Published ${notice.published_on}`;
                if (!dialog.open) dialog.showModal();
            };

            const dismiss = async () => {
                if (dismissing || !notices[index]) return;
                dismissing = true;
                const error = document.getElementById('member-notice-error');
                try {
                    const response = await fetch(notices[index].dismiss_url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (!response.ok) throw new Error('dismiss failed');
                    index += 1;
                    render();
                } catch (exception) {
                    error.hidden = false;
                    error.textContent = 'This notice could not be closed. Please try again.';
                } finally {
                    dismissing = false;
                }
            };

            dialog.querySelectorAll('[data-member-notice-dismiss]').forEach((button) => {
                button.addEventListener('click', dismiss);
            });
            dialog.addEventListener('cancel', (event) => {
                event.preventDefault();
                dismiss();
            });
            render();
        })();
    </script>
@endif

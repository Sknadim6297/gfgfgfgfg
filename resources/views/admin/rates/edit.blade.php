@extends('layouts.admin')

@section('title', 'Quick PayMoney | Exchange Rates')

@section('admin-content')
    <div class="admin-page-heading admin-rate-page-heading">
        <div>
            <span class="admin-eyebrow">PLATFORM CONFIGURATION</span>
            <h1>Exchange rates</h1>
            <p>Manage informational USDT to INR reference rates. These are not guaranteed live market quotes.</p>
        </div>
        <button class="admin-button" type="button" data-bs-toggle="modal" data-bs-target="#ratePlanModal" data-modal-mode="create">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add New Rate Plan
        </button>
    </div>

    <section class="admin-panel admin-rate-directory" aria-label="Exchange rate plans">
        <div class="admin-panel-heading">
            <div><span class="admin-eyebrow">USDT / INR</span><h2>Rate plans</h2><p>Plans are listed from the lowest minimum USD amount to the highest.</p></div>
            <span class="admin-record-count">{{ $plans->total() }} plans</span>
        </div>
        <form class="admin-filters admin-rate-filters" method="GET" action="{{ route('admin.rates.edit') }}">
            <div class="admin-search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="rate-plan-search">Search plans</label>
                <input class="portal-input" id="rate-plan-search" type="search" name="search" value="{{ request('search') }}" maxlength="120" placeholder="Search name, label, or description">
            </div>
            <div class="admin-filter-select">
                <label class="visually-hidden" for="rate-plan-status">Filter by status</label>
                <select class="portal-input" id="rate-plan-status" name="status">
                    <option value="">All statuses</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <div class="admin-filter-select">
                <label class="visually-hidden" for="rate-plan-type">Filter by plan type</label>
                <select class="portal-input" id="rate-plan-type" name="type">
                    <option value="">All plan types</option>
                    <option value="default" @selected(request('type') === 'default')>Default</option>
                    <option value="custom" @selected(request('type') === 'custom')>Custom</option>
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <button class="admin-button admin-button-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
            @if (request()->hasAny(['search', 'status', 'type']))
                <a class="admin-button admin-button-secondary" href="{{ route('admin.rates.edit') }}">Clear</a>
            @endif
        </form>
        @if ($plans->isEmpty())
            <div class="admin-empty-state"><span><i class="bi bi-currency-exchange" aria-hidden="true"></i></span><strong>No rate plans configured</strong><p>Add a plan to display a reference rate publicly.</p></div>
        @else
            <div class="admin-table-wrap">
                <table class="admin-table admin-rate-table">
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Plan name</th>
                            <th scope="col">INR per USDT</th>
                            <th scope="col">From (USDT)</th>
                            <th scope="col">Upto (USDT)</th>
                            <th scope="col">Rate label</th>
                            <th scope="col">Status</th>
                            <th scope="col">Type</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plans as $plan)
                            <tr>
                                <td><span class="admin-id">#{{ $plan->id }}</span></td>
                                <td>
                                    <span class="admin-rate-table-name"><i class="bi {{ $plan->icon }}" aria-hidden="true"></i>{{ $plan->name }}</span>
                                    @if ($plan->description)<small class="admin-rate-table-description">{{ $plan->description }}</small>@endif
                                </td>
                                <td><strong>₹{{ $plan->formattedRate() }}</strong></td>
                                <td>{{ $plan->formattedMinimumAmount() }}</td>
                                <td>{{ $plan->formattedMaximumAmount() ?? 'No limit' }}</td>
                                <td>{{ $plan->label ?: '—' }}</td>
                                <td>
                                    <span class="portal-badge {{ $plan->is_active ? 'active' : 'inactive' }}"><span class="admin-badge-dot"></span>{{ $plan->is_active ? 'Active' : 'Inactive' }}</span>
                                </td>
                                <td><span class="admin-rate-type {{ $plan->is_default ? 'default' : 'custom' }}">{{ $plan->is_default ? 'Default' : 'Custom' }}</span></td>
                                <td>
                                    <div class="admin-rate-actions">
                                        <button
                                            class="admin-icon-button"
                                            type="button"
                                            aria-label="Edit {{ $plan->name }}"
                                            title="Edit plan"
                                            data-bs-toggle="modal"
                                            data-bs-target="#ratePlanModal"
                                            data-modal-mode="edit"
                                            data-plan-id="{{ $plan->id }}"
                                            data-update-url="{{ route('admin.rates.plans.update', $plan) }}"
                                            data-name="{{ $plan->name }}"
                                            data-rate="{{ $plan->formattedRate() }}"
                                            data-minimum="{{ $plan->formattedMinimumAmount() }}"
                                            data-maximum="{{ $plan->formattedMaximumAmount() }}"
                                            data-label="{{ $plan->label }}"
                                            data-icon="{{ $plan->icon }}"
                                            data-description="{{ $plan->description }}"
                                            data-active="{{ $plan->is_active ? '1' : '0' }}"
                                            data-default="{{ $plan->is_default ? '1' : '0' }}">
                                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                        </button>
                                        <form method="POST" action="{{ route('admin.rates.plans.status', $plan) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button class="admin-icon-button admin-rate-status-button" type="submit" aria-label="{{ $plan->is_active ? 'Deactivate' : 'Activate' }} {{ $plan->name }}" title="{{ $plan->is_active ? 'Deactivate plan' : 'Activate plan' }}">
                                                <i class="bi {{ $plan->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }}" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                        @unless ($plan->is_default)
                                            <form method="POST" action="{{ route('admin.rates.plans.destroy', $plan) }}" data-confirm="Delete {{ $plan->name }}? Existing exchange requests will retain their saved rate snapshots." data-confirm-title="Delete rate plan?" data-confirm-button="Delete plan">
                                                @csrf
                                                @method('DELETE')
                                                <button class="admin-icon-button admin-rate-delete-button" type="submit" aria-label="Delete {{ $plan->name }}" title="Delete plan"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                            </form>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($plans->hasPages())
                <div class="admin-pagination">{{ $plans->links() }}</div>
            @endif
        @endif
    </section>

    <aside class="admin-rate-note admin-section-gap">
        <span class="admin-rate-note-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
        <h2>Reference rates only</h2>
        <p>Rate changes affect public display only. Existing exchange requests keep their original rate snapshots; no funds are transferred or settlement guaranteed.</p>
    </aside>

    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">CHANGE LOG</span><h2>Recent rate changes</h2><p>Audited plan creation, updates, deletions, activation, and rate changes.</p></div></div>
        @if ($history->isEmpty())
            <div class="admin-empty-state"><span><i class="bi bi-clock-history" aria-hidden="true"></i></span><strong>No rate changes recorded</strong><p>Updates to rate plans will appear here.</p></div>
        @else
            <div class="admin-table-wrap">
                <table class="admin-table admin-rate-history-table">
                    <thead><tr><th scope="col">Date</th><th scope="col">Admin</th><th scope="col">Action</th><th scope="col">Plan</th><th scope="col">Previous rate</th><th scope="col">New rate</th></tr></thead>
                    <tbody>
                        @foreach ($history as $change)
                            @php
                                $before = $change->metadata['before'] ?? null;
                                $after = $change->metadata['after'] ?? null;
                                $plan = is_array($after) ? $after : (is_array($before) ? $before : []);
                                $beforeRate = is_array($before) ? ($before['rate'] ?? null) : $before;
                                $afterRate = is_array($after) ? ($after['rate'] ?? null) : $after;
                            @endphp
                            <tr>
                                <td><time datetime="{{ $change->created_at->toIso8601String() }}">{{ $change->created_at->format('M j, Y · H:i') }}</time></td>
                                <td>{{ $change->actor?->name ?? 'Admin account removed' }}</td>
                                <td>{{ str_replace('admin.exchange_rate_', '', $change->event) }}</td>
                                <td>{{ $plan['name'] ?? 'Base Rate' }}</td>
                                <td>{{ is_string($beforeRate) ? \App\Models\ExchangeRate::formatDecimal($beforeRate) : '—' }}</td>
                                <td><strong>{{ is_string($afterRate) ? \App\Models\ExchangeRate::formatDecimal($afterRate) : 'Deleted' }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="modal fade admin-rate-modal" id="ratePlanModal" tabindex="-1" aria-labelledby="ratePlanModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form id="ratePlanForm" method="POST" action="{{ route('admin.rates.store') }}">
                    @csrf
                    <input type="hidden" name="_method" id="ratePlanMethod" value="POST">
                    <input type="hidden" name="plan_id" id="ratePlanId" value="{{ old('plan_id') }}">
                    <div class="modal-header">
                        <div><span class="admin-eyebrow">PLATFORM CONFIGURATION</span><h2 class="modal-title" id="ratePlanModalTitle">Add New Rate Plan</h2></div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="admin-rate-form-grid">
                            <div>
                                <label for="rate-plan-name">Plan Name</label>
                                <input class="portal-input" id="rate-plan-name" name="name" value="{{ old('name') }}" maxlength="120" required>
                                @error('name')<span class="admin-field-error">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label for="rate-plan-rate">INR per USDT</label>
                                <input class="portal-input" id="rate-plan-rate" name="rate" inputmode="decimal" value="{{ old('rate') }}" required>
                                @error('rate')<span class="admin-field-error">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label for="rate-plan-minimum">Minimum Amount (USDT) — From</label>
                                <input class="portal-input" id="rate-plan-minimum" name="minimum_amount" inputmode="decimal" value="{{ old('minimum_amount') }}" required>
                                @error('minimum_amount')<span class="admin-field-error">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label for="rate-plan-maximum">Maximum Amount (USDT) — Upto</label>
                                <input class="portal-input" id="rate-plan-maximum" name="maximum_amount" inputmode="decimal" value="{{ old('maximum_amount') }}" placeholder="Leave blank for no limit">
                                @error('maximum_amount')<span class="admin-field-error">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label for="rate-plan-label">Rate Label</label>
                                <input class="portal-input" id="rate-plan-label" name="label" value="{{ old('label') }}" maxlength="120">
                                @error('label')<span class="admin-field-error">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label for="rate-plan-icon">Bootstrap Icon Class</label>
                                <select class="portal-input" id="rate-plan-icon" name="icon" required>
                                    @foreach (['bi-currency-exchange', 'bi-diamond-fill', 'bi-gem', 'bi-stars', 'bi-award', 'bi-star', 'bi-cash-coin', 'bi-graph-up-arrow', 'bi-lightning-charge-fill', 'bi-bank', 'bi-trophy'] as $icon)
                                        <option value="{{ $icon }}" @selected(old('icon', 'bi-currency-exchange') === $icon)>{{ $icon }}</option>
                                    @endforeach
                                </select>
                                @error('icon')<span class="admin-field-error">{{ $message }}</span>@enderror
                            </div>
                            <div class="admin-rate-description-field">
                                <label for="rate-plan-description">Description</label>
                                <textarea class="portal-input" id="rate-plan-description" name="description" rows="3" maxlength="1000">{{ old('description') }}</textarea>
                                @error('description')<span class="admin-field-error">{{ $message }}</span>@enderror
                            </div>
                            <div class="admin-rate-active-field">
                                <input type="hidden" name="is_active" value="0">
                                <label class="admin-rate-active-toggle" for="rate-plan-active">
                                    <input id="rate-plan-active" type="checkbox" name="is_active" value="1" @checked(old('is_active', '1') === '1')>
                                    <span>Show on Public Pages</span>
                                </label>
                                @error('is_active')<span class="admin-field-error">{{ $message }}</span>@enderror
                            </div>
                        </div>
                        <p class="admin-form-help">Rate: positive, up to 8 decimal places. Slab boundaries: non-negative, up to 2 decimal places. Active slabs cannot overlap. Leave Upto blank for no limit. Base Rate must start at 0.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="admin-button admin-button-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="admin-button"><i class="bi bi-check2" aria-hidden="true"></i> <span id="ratePlanSubmitLabel">Create plan</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (() => {
            const modalElement = document.getElementById('ratePlanModal');
            const form = document.getElementById('ratePlanForm');
            if (!modalElement || !form || !window.bootstrap?.Modal) return;

            const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
            const method = document.getElementById('ratePlanMethod');
            const title = document.getElementById('ratePlanModalTitle');
            const submitLabel = document.getElementById('ratePlanSubmitLabel');
            const fields = {
                id: document.getElementById('ratePlanId'),
                name: document.getElementById('rate-plan-name'),
                rate: document.getElementById('rate-plan-rate'),
                minimum: document.getElementById('rate-plan-minimum'),
                maximum: document.getElementById('rate-plan-maximum'),
                label: document.getElementById('rate-plan-label'),
                icon: document.getElementById('rate-plan-icon'),
                description: document.getElementById('rate-plan-description'),
                active: document.getElementById('rate-plan-active'),
            };
            const createAction = @json(route('admin.rates.store'));
            const oldPlanId = @json(old('plan_id'));
            const setPlan = (button, reopen = false) => {
                const editing = button?.dataset.modalMode === 'edit';
                form.action = editing ? button.dataset.updateUrl : createAction;
                method.value = editing ? 'PUT' : 'POST';
                title.textContent = editing ? `Edit ${button.dataset.name}` : 'Add New Rate Plan';
                submitLabel.textContent = editing ? 'Save changes' : 'Create plan';
                fields.id.value = editing ? button.dataset.planId : '';
                fields.name.value = editing ? button.dataset.name : '';
                fields.rate.value = editing ? button.dataset.rate : '';
                fields.minimum.value = editing ? button.dataset.minimum : '';
                fields.maximum.value = editing ? button.dataset.maximum : '';
                fields.label.value = editing ? button.dataset.label : '';
                fields.icon.value = editing ? button.dataset.icon : 'bi-currency-exchange';
                fields.description.value = editing ? button.dataset.description : '';
                fields.active.checked = editing ? button.dataset.active === '1' : true;
                if (reopen) modal.show();
            };

            document.querySelectorAll('[data-modal-mode="create"], [data-modal-mode="edit"]').forEach((button) => {
                button.addEventListener('click', () => setPlan(button));
            });

            @if ($errors->any())
                if (oldPlanId) {
                    const editButton = document.querySelector(`[data-modal-mode="edit"][data-plan-id="${CSS.escape(String(oldPlanId))}"]`);
                    if (editButton) setPlan(editButton);
                } else {
                    setPlan(null);
                }
                fields.name.value = @json(old('name'));
                fields.rate.value = @json(old('rate'));
                fields.minimum.value = @json(old('minimum_amount'));
                fields.maximum.value = @json(old('maximum_amount'));
                fields.label.value = @json(old('label'));
                fields.icon.value = @json(old('icon', 'bi-currency-exchange'));
                fields.description.value = @json(old('description'));
                fields.active.checked = @json((string) old('is_active', '1') === '1');
                modal.show();
            @endif
        })();
    </script>
@endsection

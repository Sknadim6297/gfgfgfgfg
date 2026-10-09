<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminRateController extends Controller
{
    private const ICONS = [
        'bi-currency-exchange',
        'bi-diamond-fill',
        'bi-gem',
        'bi-stars',
        'bi-award',
        'bi-star',
        'bi-cash-coin',
        'bi-graph-up-arrow',
        'bi-lightning-charge-fill',
        'bi-bank',
        'bi-trophy',
    ];

    public function show(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'type' => ['nullable', Rule::in(['default', 'custom'])],
        ]);

        return view('admin.rates.edit', [
            'plans' => ExchangeRate::query()
                ->when($filters['search'] ?? null, function ($query, string $search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->where('name', 'like', '%'.$search.'%')
                            ->orWhere('label', 'like', '%'.$search.'%')
                            ->orWhere('description', 'like', '%'.$search.'%');
                    });
                })
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
                ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('is_default', $type === 'default'))
                ->orderBy('minimum_amount')
                ->orderBy('id')
                ->paginate(15)
                ->withQueryString(),
            'history' => AuditLog::query()
                ->whereIn('event', [
                    'admin.exchange_rate_updated',
                    'admin.exchange_rate_plan_created',
                    'admin.exchange_rate_plan_updated',
                    'admin.exchange_rate_plan_deleted',
                ])
                ->with('actor')
                ->latest()
                ->limit(30)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedPlan($request);
        $admin = $request->user('admin');

        $this->savePlanTransaction(function () use ($request, $validated, $admin): void {
            ExchangeRate::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $this->assertNoActiveOverlap($validated);
            $this->assertMinimumAvailable($validated['minimum_amount']);
            $plan = ExchangeRate::create([
                ...$validated,
                'pair' => 'USDT_INR',
                'is_default' => false,
                'updated_by_user_id' => $admin->id,
            ]);
            $this->recordPlanChange($request, $admin->id, 'admin.exchange_rate_plan_created', $plan, null);
        }, $validated);

        return back()->with('status', 'Exchange rate plan created.');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rate' => ['required', 'string', 'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,8})?$/'],
        ]);
        $admin = $request->user('admin');

        DB::transaction(function () use ($admin, $request, $validated): void {
            $rate = ExchangeRate::query()->where('plan_key', 'base')->lockForUpdate()->firstOrFail();
            $before = $rate->rate;
            $rate->forceFill([
                'rate' => $validated['rate'],
                'updated_by_user_id' => $admin->id,
            ])->save();

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'subject_user_id' => $admin->id,
                'event' => 'admin.exchange_rate_updated',
                'auditable_type' => ExchangeRate::class,
                'auditable_id' => $rate->id,
                'metadata' => [
                    'pair' => 'USDT_INR',
                    'plan_key' => 'base',
                    'before' => $before,
                    'after' => $validated['rate'],
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with('status', 'Base rate updated.');
    }

    public function updatePlan(Request $request, ExchangeRate $exchangeRate): RedirectResponse
    {
        $validated = $this->validatedPlan($request, $exchangeRate);
        $admin = $request->user('admin');

        $this->savePlanTransaction(function () use ($request, $validated, $admin, $exchangeRate): void {
            ExchangeRate::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $plan = ExchangeRate::query()->lockForUpdate()->findOrFail($exchangeRate->id);
            $this->assertNoActiveOverlap($validated, $plan->id);
            $this->assertMinimumAvailable($validated['minimum_amount'], $plan->id);
            $before = $this->planSnapshot($plan);
            $plan->fill([
                ...$validated,
                'updated_by_user_id' => $admin->id,
            ])->save();
            $this->recordPlanChange($request, $admin->id, 'admin.exchange_rate_plan_updated', $plan, $before);
        }, $validated, $exchangeRate->id);

        return back()->with('status', 'Exchange rate plan updated.');
    }

    public function toggleStatus(Request $request, ExchangeRate $exchangeRate): RedirectResponse
    {
        $admin = $request->user('admin');

        DB::transaction(function () use ($request, $admin, $exchangeRate): void {
            ExchangeRate::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $plan = ExchangeRate::query()->lockForUpdate()->findOrFail($exchangeRate->id);
            if (! $plan->is_active) {
                $this->assertNoActiveOverlap([
                    'minimum_amount' => (string) $plan->minimum_amount,
                    'maximum_amount' => $plan->maximum_amount === null ? null : (string) $plan->maximum_amount,
                    'is_active' => true,
                ], $plan->id);
            }
            $before = $this->planSnapshot($plan);
            $plan->forceFill([
                'is_active' => ! $plan->is_active,
                'updated_by_user_id' => $admin->id,
            ])->save();
            $this->recordPlanChange($request, $admin->id, 'admin.exchange_rate_plan_updated', $plan, $before);
        });

        return back()->with('status', 'Rate plan status updated.');
    }

    public function destroy(Request $request, ExchangeRate $exchangeRate): RedirectResponse
    {
        abort_if($exchangeRate->is_default, 422, 'Default rate plans cannot be deleted.');
        $admin = $request->user('admin');

        DB::transaction(function () use ($request, $admin, $exchangeRate): void {
            $plan = ExchangeRate::query()->lockForUpdate()->findOrFail($exchangeRate->id);
            abort_if($plan->is_default, 422, 'Default rate plans cannot be deleted.');

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'subject_user_id' => $admin->id,
                'event' => 'admin.exchange_rate_plan_deleted',
                'auditable_type' => ExchangeRate::class,
                'auditable_id' => $plan->id,
                'metadata' => [
                    'before' => $this->planSnapshot($plan),
                    'after' => null,
                ],
                'ip_address' => $request->ip(),
            ]);
            $plan->delete();
        });

        return back()->with('status', 'Exchange rate plan deleted.');
    }

    private function validatedPlan(Request $request, ?ExchangeRate $plan = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('exchange_rates', 'name')->ignore($plan?->id)],
            'rate' => ['required', 'string', 'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,8})?$/'],
            'minimum_amount' => [
                'required',
                'string',
                'regex:/^\d{1,18}(?:\.\d{1,8})?$/',
                Rule::unique('exchange_rates', 'minimum_amount')->ignore($plan?->id),
            ],
            'maximum_amount' => ['nullable', 'string', 'regex:/^\d{1,18}(?:\.\d{1,8})?$/'],
            'label' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'icon' => ['required', 'string', Rule::in(self::ICONS)],
            'is_active' => ['required', 'boolean'],
        ]);

        $validated['minimum_amount'] = $this->normalizeMinimum($validated['minimum_amount']);
        $maximum = $request->input('maximum_amount', $plan?->maximum_amount);
        $validated['maximum_amount'] = $maximum === null || $maximum === ''
            ? null
            : $this->normalizeMinimum($maximum);

        if (
            $validated['maximum_amount'] !== null
            && \App\Support\Decimal::compare($validated['maximum_amount'], $validated['minimum_amount'], 8) < 0
        ) {
            throw ValidationException::withMessages([
                'maximum_amount' => 'The maximum amount must be greater than or equal to the minimum amount.',
            ]);
        }

        if ($plan?->plan_key === 'base' && \App\Support\Decimal::compare($validated['minimum_amount'], '0', 8) !== 0) {
            throw ValidationException::withMessages([
                'minimum_amount' => 'The Base Rate minimum amount must remain 0.',
            ]);
        }

        return $validated;
    }

    private function assertNoActiveOverlap(array $candidate, ?int $ignoreId = null): void
    {
        if (! $candidate['is_active']) {
            return;
        }

        $plans = ExchangeRate::query()
            ->where('is_active', true)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->get(['minimum_amount', 'maximum_amount']);

        foreach ($plans as $plan) {
            $candidateStartsBeforePlanEnds = $candidate['maximum_amount'] === null
                || \App\Support\Decimal::compare(
                    (string) $plan->minimum_amount,
                    $candidate['maximum_amount'],
                    8,
                ) <= 0;
            $planStartsBeforeCandidateEnds = $plan->maximum_amount === null
                || \App\Support\Decimal::compare(
                    (string) $plan->maximum_amount,
                    $candidate['minimum_amount'],
                    8,
                ) >= 0;

            if ($candidateStartsBeforePlanEnds && $planStartsBeforeCandidateEnds) {
                throw ValidationException::withMessages([
                    'maximum_amount' => 'Active rate slabs cannot overlap.',
                ]);
            }
        }
    }

    private function assertMinimumAvailable(string $minimum, ?int $ignoreId = null): void
    {
        $query = ExchangeRate::query()->where('minimum_amount', $minimum);
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }

        abort_if($query->exists(), 422, 'Each rate plan must have a unique minimum amount.');
    }

    private function normalizeMinimum(string $minimum): string
    {
        [$whole, $fraction] = array_pad(explode('.', $minimum, 2), 2, '');

        $whole = ltrim($whole, '0');

        return ($whole === '' ? '0' : $whole).'.'.str_pad($fraction, 8, '0');
    }

    private function savePlanTransaction(callable $callback, array $validated, ?int $ignoreId = null): void
    {
        try {
            DB::transaction($callback);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ($sqlState !== '23000' && $driverCode !== 19 && $driverCode !== 1062) {
                throw $exception;
            }

            $errors = [];
            $nameQuery = ExchangeRate::query()->where('name', $validated['name']);
            $minimumQuery = ExchangeRate::query()->where('minimum_amount', $validated['minimum_amount']);
            if ($ignoreId !== null) {
                $nameQuery->where('id', '<>', $ignoreId);
                $minimumQuery->where('id', '<>', $ignoreId);
            }
            if ($nameQuery->exists()) {
                $errors['name'] = 'A rate plan with this name already exists.';
            }
            if ($minimumQuery->exists()) {
                $errors['minimum_amount'] = 'Each rate plan must have a unique minimum amount.';
            }
            if (isset($validated['maximum_amount'])) {
                $maximumQuery = ExchangeRate::query()->where('minimum_amount', $validated['maximum_amount']);
                if ($ignoreId !== null) {
                    $maximumQuery->where('id', '<>', $ignoreId);
                }
                if ($maximumQuery->exists()) {
                    $errors['maximum_amount'] = 'A rate slab cannot end at another plan’s starting amount.';
                }
            }
            if ($errors === []) {
                throw $exception;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private function recordPlanChange(
        Request $request,
        int $adminId,
        string $event,
        ExchangeRate $plan,
        ?array $before,
    ): void {
        AuditLog::create([
            'actor_user_id' => $adminId,
            'subject_user_id' => $adminId,
            'event' => $event,
            'auditable_type' => ExchangeRate::class,
            'auditable_id' => $plan->id,
            'metadata' => [
                'before' => $before,
                'after' => $this->planSnapshot($plan),
            ],
            'ip_address' => $request->ip(),
        ]);
    }

    private function planSnapshot(ExchangeRate $plan): array
    {
        return [
            'plan_key' => $plan->plan_key,
            'name' => $plan->name,
            'rate' => $plan->rate,
            'minimum_amount' => $plan->minimum_amount,
            'maximum_amount' => $plan->maximum_amount,
            'label' => $plan->label,
            'description' => $plan->description,
            'icon' => $plan->icon,
            'is_active' => $plan->is_active,
            'is_default' => $plan->is_default,
        ];
    }
}

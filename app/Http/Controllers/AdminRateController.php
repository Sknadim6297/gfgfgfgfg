<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Support\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminRateController extends Controller
{
    private const FIXED_SLABS = [
        'base' => ['minimum' => '0', 'maximum' => '9999.99999999'],
        'prime' => ['minimum' => '10000', 'maximum' => '19999.99999999'],
        'vip' => ['minimum' => '20000', 'maximum' => null],
    ];

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
        $summary = ExchangeRate::query()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END) as active_count', [true])
            ->selectRaw('SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END) as inactive_count', [false])
            ->selectRaw('SUM(CASE WHEN is_default = ? THEN 1 ELSE 0 END) as default_count', [true])
            ->selectRaw('SUM(CASE WHEN is_default = ? THEN 1 ELSE 0 END) as custom_count', [false])
            ->first();

        return view('admin.rates.edit', [
            'summary' => $summary,
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
        return back()->with('error', 'Only the Base, Prime, and VIP rate plans are available.');
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
        $normalized = [
            'rate' => $this->plainDecimal($request->input('rate')),
            'minimum_amount' => $this->plainDecimal($request->input('minimum_amount')),
        ];
        if ($request->exists('maximum_amount')) {
            $normalized['maximum_amount'] = $this->plainDecimal($request->input('maximum_amount'));
        }
        $request->merge($normalized);

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
            && Decimal::compare($validated['maximum_amount'], $validated['minimum_amount'], 8) < 0
        ) {
            throw ValidationException::withMessages([
                'maximum_amount' => 'The maximum amount must be greater than or equal to the minimum amount.',
            ]);
        }

        if ($plan?->plan_key === 'base' && Decimal::compare($validated['minimum_amount'], '0', 8) !== 0) {
            throw ValidationException::withMessages([
                'minimum_amount' => 'The Base Rate minimum amount must remain 0.',
            ]);
        }

        if ($plan !== null) {
            $this->assertFixedSlab($plan, $validated);
        }

        return $validated;
    }

    private function assertFixedSlab(ExchangeRate $plan, array $validated): void
    {
        $slab = self::FIXED_SLABS[$plan->plan_key] ?? null;
        if ($slab === null) {
            return;
        }

        $minimumMatches = Decimal::compare($validated['minimum_amount'], $slab['minimum'], 8) === 0;
        $maximumMatches = $slab['maximum'] === null
            ? $validated['maximum_amount'] === null
            : $validated['maximum_amount'] !== null
                && Decimal::compare($validated['maximum_amount'], $slab['maximum'], 8) === 0;

        if ($minimumMatches && $maximumMatches) {
            return;
        }

        $message = match ($plan->plan_key) {
            'base' => 'Base covers amounts from 0 USDT up to below 10,000 USDT. That range cannot be changed.',
            'prime' => 'Prime covers amounts from 10,000 USDT up to below 20,000 USDT. That range cannot be changed.',
            'vip' => 'VIP covers amounts of 20,000 USDT and above. That range cannot be changed.',
            default => 'This rate plan range cannot be changed.',
        };

        throw ValidationException::withMessages([
            'minimum_amount' => $message,
            'maximum_amount' => $message,
        ]);
    }

    private function assertNoActiveOverlap(array $candidate, ?int $ignoreId = null): void
    {
        if (! $candidate['is_active']) {
            return;
        }

        $plans = ExchangeRate::query()
            ->where('is_active', true)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->orderBy('minimum_amount')
            ->orderBy('id')
            ->get(['id', 'name', 'minimum_amount', 'maximum_amount']);

        foreach ($plans as $plan) {
            if (! $this->rangesOverlap(
                $candidate['minimum_amount'],
                $candidate['maximum_amount'],
                (string) $plan->minimum_amount,
                $plan->maximum_amount === null ? null : (string) $plan->maximum_amount,
            )) {
                continue;
            }

            $message = $this->overlapMessage($plan);

            throw ValidationException::withMessages([
                'minimum_amount' => $message,
                'maximum_amount' => $message,
            ]);
        }
    }

    private function rangesOverlap(string $leftMinimum, ?string $leftMaximum, string $rightMinimum, ?string $rightMaximum): bool
    {
        $leftStartsBeforeRightEnds = $leftMaximum === null
            || Decimal::compare($rightMinimum, $leftMaximum, 8) <= 0;
        $rightStartsBeforeLeftEnds = $rightMaximum === null
            || Decimal::compare($rightMaximum, $leftMinimum, 8) >= 0;

        return $leftStartsBeforeRightEnds && $rightStartsBeforeLeftEnds;
    }

    private function overlapMessage(ExchangeRate $plan): string
    {
        $minimum = ExchangeRate::formatDecimal((string) $plan->minimum_amount);
        $range = $plan->maximum_amount === null
            ? $minimum.' USDT and above'
            : $minimum.' to '.ExchangeRate::formatDecimal((string) $plan->maximum_amount).' USDT';

        return 'This range overlaps the active plan '.$plan->name.' ('.$range.'). Active slabs include both endpoints. Narrow that plan or choose a range outside it.';
    }

    private function assertMinimumAvailable(string $minimum, ?int $ignoreId = null): void
    {
        $query = ExchangeRate::query()->where('minimum_amount', $minimum);
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'minimum_amount' => 'Each rate plan must have a unique minimum amount.',
            ]);
        }
    }

    private function plainDecimal(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return str_replace([',', ' '], '', trim($value));
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

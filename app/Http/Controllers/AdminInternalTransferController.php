<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InternalTransferSettings;
use App\Models\InternalUsdtTransfer;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminInternalTransferController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(InternalUsdtTransfer::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $transfers = InternalUsdtTransfer::query()
            ->with(['sender:id,name,customer_id', 'recipient:id,name,customer_id'])
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('transfer_reference', 'like', '%'.$search.'%')
                        ->orWhereHas('sender', fn ($users) => $users->where('customer_id', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('recipient', fn ($users) => $users->where('customer_id', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.transfers.index', [
            'transfers' => $transfers,
            'settings' => InternalTransferSettings::query()->find(1),
        ]);
    }

    public function editSettings(): View
    {
        return view('admin.transfers.settings', [
            'settings' => InternalTransferSettings::query()->find(1),
            'history' => AuditLog::query()
                ->where('event', 'admin.internal_transfer_settings_updated')
                ->with('actor')
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'minimum_amount' => ['required', 'string', 'regex:/\A(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?\z/'],
            'maximum_amount' => ['nullable', 'string', 'regex:/\A(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?\z/'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $minimum = $this->normalizeAmount($validated['minimum_amount']);
        $maximum = filled($validated['maximum_amount'] ?? null)
            ? $this->normalizeAmount($validated['maximum_amount'])
            : null;

        if (Decimal::compare($minimum, '0', 8) <= 0) {
            throw ValidationException::withMessages([
                'minimum_amount' => 'The minimum transfer must be greater than zero.',
            ]);
        }

        if ($maximum !== null && Decimal::compare($maximum, $minimum, 8) < 0) {
            throw ValidationException::withMessages([
                'maximum_amount' => 'The maximum transfer must be at least the minimum transfer.',
            ]);
        }

        $settings = InternalTransferSettings::query()->find(1) ?? new InternalTransferSettings(['id' => 1]);
        $before = [
            'minimum_amount' => $settings->minimum_amount,
            'maximum_amount' => $settings->maximum_amount,
            'is_enabled' => (bool) $settings->is_enabled,
        ];
        $settings->fill([
            'minimum_amount' => $minimum,
            'maximum_amount' => $maximum,
            'is_enabled' => $request->boolean('is_enabled'),
            'updated_by_user_id' => $request->user('admin')->id,
        ])->save();

        AuditLog::create([
            'actor_user_id' => $request->user('admin')->id,
            'subject_user_id' => $request->user('admin')->id,
            'event' => 'admin.internal_transfer_settings_updated',
            'auditable_type' => InternalTransferSettings::class,
            'auditable_id' => $settings->id,
            'metadata' => [
                'before' => $before,
                'after' => [
                    'minimum_amount' => $settings->minimum_amount,
                    'maximum_amount' => $settings->maximum_amount,
                    'is_enabled' => (bool) $settings->is_enabled,
                ],
            ],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('status', 'Internal transfer settings updated.');
    }

    private function normalizeAmount(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 8, '0');
    }
}

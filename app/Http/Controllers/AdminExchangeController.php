<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\ExchangeRequest;
use App\Notifications\ExchangeStatusUpdated;
use App\Services\SellRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminExchangeController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(ExchangeRequest::STATUSES)],
            'rate_plan' => ['nullable', Rule::exists('exchange_rates', 'plan_key')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $summary = ExchangeRequest::query()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('COALESCE(ROUND(SUM(usdt_amount), 8), 0) as total_usdt')
            ->selectRaw('COALESCE(ROUND(SUM(inr_amount), 2), 0) as total_inr')
            ->selectRaw(
                'COALESCE(ROUND(SUM(CASE WHEN status IN (?, ?) THEN usdt_amount ELSE 0 END), 8), 0) as pending_usdt',
                ['pending', 'processing']
            )
            ->selectRaw(
                'COALESCE(ROUND(SUM(CASE WHEN status IN (?, ?) THEN inr_amount ELSE 0 END), 2), 0) as pending_inr',
                ['pending', 'processing']
            )
            ->selectRaw(
                'COALESCE(ROUND(SUM(CASE WHEN status IN (?, ?) THEN usdt_amount ELSE 0 END), 8), 0) as approved_usdt',
                ['approved', 'completed']
            )
            ->selectRaw(
                'COALESCE(ROUND(SUM(CASE WHEN status IN (?, ?) THEN inr_amount ELSE 0 END), 2), 0) as approved_inr',
                ['approved', 'completed']
            )
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN status = ? THEN usdt_amount ELSE 0 END), 8), 0) as rejected_usdt', ['rejected'])
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN status = ? THEN inr_amount ELSE 0 END), 2), 0) as rejected_inr', ['rejected'])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as pending_count', ['pending', 'processing'])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as approved_count', ['approved', 'completed'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected_count', ['rejected'])
            ->first();

        $exchanges = ExchangeRequest::with('user:id,name,email,customer_id')
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('transaction_reference', 'like', '%'.$search.'%')
                        ->orWhere('request_reference', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn ($users) => $users
                            ->where('customer_id', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['rate_plan'] ?? null, fn ($query, string $plan) => $query->where('rate_plan_key', $plan))
            ->when($validated['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.exchanges.index', [
            'exchanges' => $exchanges,
            'summary' => $summary,
            'ratePlans' => ExchangeRate::query()->orderBy('minimum_amount')->get(['plan_key', 'name']),
        ]);
    }

    public function show(ExchangeRequest $exchangeRequest, SellRequestService $sellRequests): View
    {
        abort_unless($exchangeRequest->request_reference !== null, 404);
        $exchangeRequest->load('user');

        return view('admin.exchanges.show', [
            'exchange' => $exchangeRequest,
            'availableBalance' => $sellRequests->availableBalance($exchangeRequest->user),
            'audit' => AuditLog::where('auditable_type', ExchangeRequest::class)
                ->where('auditable_id', $exchangeRequest->id)->with('actor')->latest()->get(),
        ]);
    }

    public function reject(Request $request, ExchangeRequest $exchangeRequest, SellRequestService $sellRequests): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $sellRequests->reject($exchangeRequest, $request->user('admin'), $validated['reason'], $request->ip());

        return back()->with('status', 'Sell request rejected and its USD reservation released.');
    }

    public function update(Request $request, ExchangeRequest $exchangeRequest, SellRequestService $sellRequests): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(ExchangeRequest::STATUSES)],
            'transaction_reference' => ['nullable', 'string', 'max:150', Rule::unique('exchange_requests', 'transaction_reference')->ignore($exchangeRequest->id)],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($request, $exchangeRequest, $validated, $sellRequests): void {
            $exchange = ExchangeRequest::whereKey($exchangeRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($exchange->available_usd_before === null, 422, 'Use the sell request approval or rejection action for this request.');
            $allowed = match ($exchange->status) {
                'pending' => ['processing', 'rejected'],
                'processing' => ['completed', 'rejected'],
                default => [],
            };

            abort_unless(in_array($validated['status'], $allowed, true), 422, 'This exchange status transition is not allowed.');
            abort_if($validated['status'] === 'completed' && empty($validated['transaction_reference']), 422, 'A transaction reference is required before completion.');

            $before = ['status' => $exchange->status, 'transaction_reference' => $exchange->transaction_reference];

            if ($validated['status'] === 'completed') {
                $sellRequests->finalizeLegacyExchange($exchange, $request->user('admin'));
            }

            $exchange->fill($validated)->save();
            AuditLog::create([
                'actor_user_id' => $request->user('admin')->id,
                'subject_user_id' => $exchange->user_id,
                'event' => 'admin.exchange_status_updated',
                'auditable_type' => ExchangeRequest::class,
                'auditable_id' => $exchange->id,
                'metadata' => ['before' => $before, 'after' => $validated],
                'ip_address' => $request->ip(),
            ]);

            DB::afterCommit(fn () => $exchange->user->notify(new ExchangeStatusUpdated($exchange)));
        });

        return back()->with('status', 'Exchange request updated.');
    }
}

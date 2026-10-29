<?php

namespace App\Http\Controllers;

use App\Models\InternalUsdtTransfer;
use App\Services\InternalUsdtTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class InternalUsdtTransferController extends Controller
{
    public function __construct(private readonly InternalUsdtTransferService $transfers) {}

    public function index(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        $preview = session('recipient_preview');
        if (! is_array($preview) && is_string(old('recipient_customer_id')) && old('recipient_customer_id') !== '') {
            try {
                $recipient = $this->transfers->locateRecipient($user, (string) old('recipient_customer_id'));
                $preview = [
                    'customer_id' => $recipient->customer_id,
                    'name' => $recipient->name,
                ];
            } catch (ValidationException) {
                $preview = null;
            }
        }

        $history = InternalUsdtTransfer::query()
            ->where(function ($query) use ($user): void {
                $query->where('sender_user_id', $user->id)
                    ->orWhere('recipient_user_id', $user->id);
            })
            ->with(['sender:id,name,customer_id', 'recipient:id,name,customer_id'])
            ->latest()
            ->paginate(10);

        return view('pages.wallet.transfer', [
            'user' => $user,
            'settings' => $this->transfers->settings(),
            'transfersEnabled' => $this->transfers->enabled(),
            'availableBalance' => $this->transfers->availableBalance($user),
            'preview' => $preview,
            'history' => $history,
            'submissionKey' => old('submission_key', (string) \Illuminate\Support\Str::uuid()),
        ]);
    }

    public function preview(Request $request): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);
        $validated = $request->validate([
            'recipient_customer_id' => ['required', 'string', 'max:20'],
        ]);

        if (! $this->transfers->enabled()) {
            throw ValidationException::withMessages([
                'recipient_customer_id' => 'Internal USDT transfers are currently unavailable.',
            ]);
        }

        $recipient = $this->transfers->locateRecipient($user, $validated['recipient_customer_id']);

        return back()->withInput()->with('recipient_preview', [
            'customer_id' => $recipient->customer_id,
            'name' => $recipient->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);
        $validated = $request->validate([
            'recipient_customer_id' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'string', 'max:21'],
            'wallet_transaction_pin' => ['required', 'string', 'max:12'],
            'submission_key' => ['required', 'uuid'],
            'currency' => ['nullable', Rule::in([InternalUsdtTransfer::CURRENCY])],
        ]);

        try {
            $transfer = $this->transfers->transfer(
                $user,
                $validated['recipient_customer_id'],
                $validated['amount'],
                $validated['wallet_transaction_pin'],
                $validated['submission_key'],
                $request->ip(),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'The transfer could not be completed. Please try again.');
        }

        return redirect()
            ->route('wallet.transfers.index')
            ->with('status', 'Transfer '.$transfer->transfer_reference.' completed.');
    }
}

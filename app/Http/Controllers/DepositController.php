<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\DepositSettings;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class DepositController extends Controller
{
    public function create(Request $request): View
    {
        abort_unless($request->user('web')?->role === 'user', 403);

        $settings = DepositSettings::query()->find(1);
        $qrPath = $settings?->qr_path;
        $qrImageAvailable = DepositSettings::isManagedQrPath($qrPath) && Storage::disk('local')->exists($qrPath);

        return view('pages.deposit.create', [
            'settings' => $settings,
            'qrImageAvailable' => $qrImageAvailable,
            'paymentSettingsAvailable' => $this->hasActivePaymentSettings($settings, $qrImageAvailable),
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'submission_key' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/'],
            'transaction_reference' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9][A-Za-z0-9\\/-]{3,149}$/'],
            'payment_proof' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $amount = Money::normalizeUsd($validated['amount']);
        if ($amount === '0.00') {
            throw ValidationException::withMessages(['amount' => 'The deposit amount must be greater than zero.']);
        }

        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);
        $reference = Str::upper(trim($validated['transaction_reference']));

        $existingBySubmission = Deposit::query()
            ->where('user_id', $user->id)
            ->where('submission_key', $validated['submission_key'])
            ->first();

        if ($existingBySubmission) {
            return $this->submitted($existingBySubmission);
        }

        $settings = DepositSettings::query()->find(1);
        $qrPath = $settings?->qr_path;
        $qrImageAvailable = DepositSettings::isManagedQrPath($qrPath) && Storage::disk('local')->exists($qrPath);
        if (! $this->hasActivePaymentSettings($settings, $qrImageAvailable)) {
            return back()->withInput()->withErrors([
                'payment' => 'Payment instructions are currently unavailable. Please try again later.',
            ]);
        }

        if (Deposit::query()->where('transaction_reference', $reference)->exists()) {
            throw ValidationException::withMessages([
                'transaction_reference' => 'This transaction reference has already been submitted.',
            ]);
        }

        $proofPath = null;
        try {
            if ($request->hasFile('payment_proof')) {
                $proofPath = $request->file('payment_proof')->store('deposits/proofs', 'local');
                if (! $proofPath) {
                    throw new RuntimeException('The payment proof could not be stored.');
                }
            }

            $deposit = DB::transaction(function () use ($request, $user, $validated, $amount, $reference, &$proofPath): Deposit {
                $deposit = Deposit::create([
                    'deposit_id' => 'REF-'.now()->format('Ymd').'-'.Str::upper(Str::random(12)),
                    'user_id' => $user->id,
                    'submission_key' => $validated['submission_key'],
                    'amount' => $amount,
                    'transaction_reference' => $reference,
                    'proof_path' => $proofPath,
                    'status' => 'pending',
                    'submitted_at' => now(),
                ]);

                AuditLog::create([
                    'actor_user_id' => $user->id,
                    'subject_user_id' => $user->id,
                    'event' => 'deposit.submitted',
                    'auditable_type' => Deposit::class,
                    'auditable_id' => $deposit->id,
                    'metadata' => ['amount' => $amount, 'transaction_reference' => $reference],
                    'ip_address' => $request->ip(),
                ]);

                return $deposit;
            });
        } catch (QueryException $exception) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }

            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = Deposit::query()
                ->where('user_id', $user->id)
                ->where('submission_key', $validated['submission_key'])
                ->first();

            if ($existing) {
                return $this->submitted($existing);
            }

            if (Deposit::query()->where('transaction_reference', $reference)->exists()) {
                throw ValidationException::withMessages([
                    'transaction_reference' => 'This transaction reference has already been submitted.',
                ]);
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }

            throw $exception;
        }

        return $this->submitted($deposit);
    }

    public function show(Request $request, Deposit $deposit): View
    {
        Gate::authorize('view', $deposit);

        return view('pages.deposit.show', ['deposit' => $deposit]);
    }

    public function paymentQr(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user('web')?->role === 'user', 403);

        $settings = DepositSettings::query()->find(1);
        $qrPath = $settings?->qr_path;
        $qrImageAvailable = DepositSettings::isManagedQrPath($qrPath) && Storage::disk('local')->exists($qrPath);
        abort_unless($this->hasActivePaymentSettings($settings, $qrImageAvailable), 404);

        return Storage::disk('local')->response($qrPath, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function proof(Request $request, Deposit $deposit): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        Gate::authorize('view', $deposit);
        abort_unless($deposit->proof_path && Storage::disk('local')->exists($deposit->proof_path), 404);

        return Storage::disk('local')->response($deposit->proof_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function submitted(Deposit $deposit): RedirectResponse
    {
        return redirect()->route('deposits.show', $deposit)
            ->with('status', 'Your deposit request has been submitted successfully. Your payment is being reviewed and will be processed shortly.');
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 19;
    }

    private function hasActivePaymentSettings(?DepositSettings $settings, bool $qrImageAvailable): bool
    {
        return $qrImageAvailable
            && filled($settings?->recipient_name)
            && filled($settings?->instructions);
    }
}

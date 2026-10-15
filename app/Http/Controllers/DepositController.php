<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\DepositSettings;
use App\Support\Decimal;
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
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        $settings = DepositSettings::query()->find(1);
        $qrPath = $settings?->qr_path;
        $qrImageAvailable = DepositSettings::isManagedQrPath($qrPath) && Storage::disk('local')->exists($qrPath);
        $logoImageAvailable = DepositSettings::isManagedLogoPath($settings?->logo_path)
            && Storage::disk('local')->exists($settings->logo_path);
        $paymentSettingsAvailable = $settings?->hasActiveBep20Settings($logoImageAvailable, $qrImageAvailable) ?? false;
        $submissionKey = $request->session()->get('deposit_submission_key');
        if (! is_string($submissionKey) || ! Str::isUuid($submissionKey)) {
            $submissionKey = (string) Str::uuid();
            $request->session()->put('deposit_submission_key', $submissionKey);
        }

        return view('pages.deposit.create', [
            'settings' => $settings,
            'qrImageAvailable' => $qrImageAvailable,
            'logoImageAvailable' => $logoImageAvailable,
            'paymentSettingsAvailable' => $paymentSettingsAvailable,
            'submissionKey' => $submissionKey,
            'availableBalance' => (string) $user->balance,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);
        $validated = $request->validate(['submission_key' => ['required', 'uuid']]);

        $existingBySubmission = Deposit::query()
            ->where('user_id', $user->id)
            ->where('submission_key', $validated['submission_key'])
            ->first();

        if ($existingBySubmission) {
            return $this->submitted($existingBySubmission);
        }

        $amountData = $request->validate([
            'amount' => ['required', 'string', 'regex:/\A(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?\z/'],
        ]);
        $amount = $this->normalizeUsdt($amountData['amount']);
        if (Decimal::compare($amount, '0', 8) <= 0) {
            throw ValidationException::withMessages(['amount' => 'The deposit amount must be greater than zero.']);
        }

        $settings = DepositSettings::query()->find(1);
        if (! $this->activeDepositSettings($settings)) {
            return back()->withInput()->withErrors([
                'payment' => 'BEP20 deposit settings are currently unavailable or disabled. Please try again later.',
            ]);
        }

        if (Decimal::compare($amount, (string) $settings->minimum_amount, 8) < 0) {
            throw ValidationException::withMessages([
                'amount' => 'The minimum deposit is '.Money::formatUsdt((string) $settings->minimum_amount).' USDT.',
            ]);
        }

        $snapshotPaths = [];
        try {
            $deposit = DB::transaction(function () use ($request, $user, $validated, $amount, &$snapshotPaths): Deposit {
                $settings = DepositSettings::query()->lockForUpdate()->find(1);
                if (! $this->activeDepositSettings($settings)) {
                    throw ValidationException::withMessages([
                        'payment' => 'BEP20 deposit settings are currently unavailable or disabled. Please try again later.',
                    ]);
                }

                if (Decimal::compare($amount, (string) $settings->minimum_amount, 8) < 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'The minimum deposit is '.Money::formatUsdt((string) $settings->minimum_amount).' USDT.',
                    ]);
                }

                $logoSnapshot = $this->snapshotImage($settings->logo_path, 'logo');
                $snapshotPaths[] = $logoSnapshot;
                $qrSnapshot = $this->snapshotImage($settings->qr_path, 'qr');
                $snapshotPaths[] = $qrSnapshot;
                $deposit = Deposit::create([
                    'deposit_id' => Deposit::temporaryId(),
                    'user_id' => $user->id,
                    'submission_key' => $validated['submission_key'],
                    'amount' => $amount,
                    'transaction_reference' => null,
                    'status' => 'pending',
                    'submitted_at' => now(),
                    'network_name' => $settings->network_name,
                    'blockchain' => $settings->blockchain,
                    'token_symbol' => 'USDT',
                    'token_contract_address' => $settings->token_contract_address,
                    'receiving_wallet_address' => $settings->receiving_wallet_address,
                    'recipient_name_snapshot' => $settings->recipient_name,
                    'network_logo_path' => $logoSnapshot,
                    'qr_snapshot_path' => $qrSnapshot,
                    'minimum_amount_snapshot' => $settings->minimum_amount,
                    'instructions_snapshot' => $settings->instructions,
                ]);
                $deposit->forceFill([
                    'deposit_id' => 'DEP'.now()->format('Ymd').str_pad((string) $deposit->id, 6, '0', STR_PAD_LEFT),
                ])->save();

                AuditLog::create([
                    'actor_user_id' => $user->id,
                    'subject_user_id' => $user->id,
                    'event' => 'deposit.submitted',
                    'auditable_type' => Deposit::class,
                    'auditable_id' => $deposit->id,
                    'metadata' => [
                        'amount' => $amount,
                        'network_name' => $deposit->network_name,
                        'blockchain' => $deposit->blockchain,
                    ],
                    'ip_address' => $request->ip(),
                ]);

                return $deposit;
            });
        } catch (QueryException $exception) {
            $this->removeSnapshots($snapshotPaths);

            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = Deposit::query()
                ->where('user_id', $user->id)
                ->where('submission_key', $validated['submission_key'])
                ->first();

            if ($existing) {
                $request->session()->forget('deposit_submission_key');

                return $this->submitted($existing);
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->removeSnapshots($snapshotPaths);

            throw $exception;
        }

        $request->session()->forget('deposit_submission_key');

        return $this->submitted($deposit);
    }

    public function show(Request $request, Deposit $deposit): View
    {
        Gate::authorize('view', $deposit);

        return view('pages.deposit.show', ['deposit' => $deposit]);
    }

    public function submitTransactionHash(Request $request, Deposit $deposit): RedirectResponse
    {
        Gate::authorize('view', $deposit);
        $validated = $request->validate([
            'submission_key' => ['required', 'uuid'],
            'transaction_hash' => ['required', 'string', 'max:66'],
        ]);
        abort_unless(hash_equals((string) $deposit->submission_key, $validated['submission_key']), 422, 'This deposit submission token is invalid.');
        $hash = $validated['transaction_hash'];

        try {
            DB::transaction(function () use ($request, $deposit, $hash): void {
                $locked = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'pending') {
                    abort(422, 'A transaction ID cannot be submitted for a resolved deposit.');
                }

                if ($locked->transaction_hash !== null) {
                    if (hash_equals($locked->transaction_hash, $hash)) {
                        return;
                    }

                    throw ValidationException::withMessages([
                        'transaction_hash' => 'A transaction ID has already been submitted for this deposit.',
                    ]);
                }

                if (Deposit::query()->where('transaction_hash', $hash)->exists()) {
                    throw ValidationException::withMessages([
                        'transaction_hash' => 'This transaction ID has already been claimed for another deposit.',
                    ]);
                }

                $locked->forceFill(['transaction_hash' => $hash])->save();
                AuditLog::create([
                    'actor_user_id' => $request->user('web')->id,
                    'subject_user_id' => $request->user('web')->id,
                    'event' => 'deposit.transaction_hash_submitted',
                    'auditable_type' => Deposit::class,
                    'auditable_id' => $locked->id,
                    'metadata' => [
                        'deposit_id' => $locked->deposit_id,
                        'transaction_hash' => $hash,
                        'verification' => 'manual_review_required',
                    ],
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)
                && Deposit::query()->where('transaction_hash', $hash)->where('id', '!=', $deposit->id)->exists()) {
                throw ValidationException::withMessages([
                    'transaction_hash' => 'This transaction ID has already been claimed for another deposit.',
                ]);
            }

            throw $exception;
        }

        return redirect()->route('deposits.show', $deposit)
            ->with('status', 'Transaction ID submitted. It is a payment claim only and requires independent admin verification.');
    }

    public function networkLogo(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user('web')?->role === 'user', 403);
        $settings = DepositSettings::query()->find(1);
        abort_unless($this->activeDepositSettings($settings), 404);

        return Storage::disk('local')->response($settings->logo_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function image(Request $request, Deposit $deposit, string $image): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        Gate::authorize('view', $deposit);
        $path = $image === 'logo' ? $deposit->network_logo_path : $deposit->qr_snapshot_path;
        abort_unless($this->isSnapshotPath($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function paymentQr(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user('web')?->role === 'user', 403);

        $settings = DepositSettings::query()->find(1);
        abort_unless($this->activeDepositSettings($settings), 404);

        return Storage::disk('local')->response($settings->qr_path, null, [
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
            ->with('status', 'Your deposit request is ready. Send USDT only on BEP20, then submit the transaction ID for manual review.');
    }

    private function activeDepositSettings(?DepositSettings $settings): bool
    {
        if (! $settings) {
            return false;
        }

        $logoPath = $settings->logo_path;
        $qrPath = $settings->qr_path;
        $logoAvailable = DepositSettings::isManagedLogoPath($logoPath) && Storage::disk('local')->exists($logoPath);
        $qrAvailable = DepositSettings::isManagedQrPath($qrPath) && Storage::disk('local')->exists($qrPath);

        return $settings->hasActiveBep20Settings($logoAvailable, $qrAvailable);
    }

    private function snapshotImage(?string $path, string $kind): string
    {
        $managed = $kind === 'logo'
            ? DepositSettings::isManagedLogoPath($path)
            : DepositSettings::isManagedQrPath($path);
        if (! $managed || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('A configured deposit image is unavailable.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            throw new RuntimeException('A configured deposit image has an unsupported file type.');
        }

        $snapshotPath = 'deposits/snapshots/'.Str::uuid().'-'.$kind.'.'.$extension;
        if (! Storage::disk('local')->copy($path, $snapshotPath)) {
            throw new RuntimeException('A deposit settings image could not be snapshotted.');
        }

        return $snapshotPath;
    }

    private function isSnapshotPath(?string $path): bool
    {
        return is_string($path)
            && preg_match('~\Adeposits/snapshots/[A-Za-z0-9-]+-(?:logo|qr)\.(?:png|jpe?g|webp)\z~D', $path) === 1;
    }

    private function removeSnapshots(array $paths): void
    {
        foreach ($paths as $path) {
            if ($this->isSnapshotPath($path) && Storage::disk('local')->exists($path)) {
                if (! Storage::disk('local')->delete($path)) {
                    throw new RuntimeException('A temporary deposit settings image snapshot could not be removed.');
                }
            }
        }
    }

    private function normalizeUsdt(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 8, '0');
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 19;
    }

}

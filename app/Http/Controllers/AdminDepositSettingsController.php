<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DepositSettings;
use App\Support\Decimal;
use App\Support\DepositSettingsPersistence;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdminDepositSettingsController extends Controller
{
    public function edit(): View
    {
        $settings = DepositSettings::query()->find(1);
        $qrPath = $settings?->qr_path;

        return view('admin.deposits.settings', [
            'settings' => $settings,
            'qrImageAvailable' => DepositSettings::isManagedQrPath($qrPath) && Storage::disk('local')->exists($qrPath),
            'logoImageAvailable' => DepositSettings::isManagedLogoPath($settings?->logo_path)
                && Storage::disk('local')->exists($settings->logo_path),
            'storageReady' => DepositSettingsPersistence::storageReady(),
            'history' => AuditLog::query()
                ->whereIn('event', ['admin.deposit_settings_updated', 'admin.deposit_qr_updated', 'admin.deposit_settings_deleted'])
                ->with('actor')
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        if (! DepositSettingsPersistence::storageReady()) {
            $this->logDepositSettingsFailure(new RuntimeException('Deposit settings storage is missing required columns.'));

            return $this->failedUpdateResponse($request, DepositSettingsPersistence::missingStorageMessage());
        }

        $validated = $request->validate([
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'network_name' => ['nullable', 'string', 'in:BEP20'],
            'blockchain' => ['nullable', 'string', 'in:BNB Smart Chain'],
            'token_contract_address' => ['sometimes', 'nullable', 'string', 'max:42'],
            'receiving_wallet_address' => ['nullable', 'string', 'max:42'],
            'minimum_amount' => ['sometimes', 'required', 'string', 'regex:/\A(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?\z/'],
            'is_enabled' => ['nullable', 'boolean'],
            'logo_image' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'qr_image' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_qr' => ['nullable', 'boolean'],
        ]);

        $settings = DepositSettings::query()->find(1);
        $contractAddress = $request->exists('token_contract_address')
            ? ($validated['token_contract_address'] ?? null)
            : $settings?->token_contract_address;
        $requestedEnable = $request->exists('is_enabled')
            ? (bool) $validated['is_enabled']
            : (bool) $settings?->is_enabled;
        $enabled = $requestedEnable;
        $receivingAddress = $request->exists('receiving_wallet_address')
            ? ($validated['receiving_wallet_address'] ?? null)
            : $settings?->receiving_wallet_address;

        if ($enabled && ! $this->tokenContractIsVerified($contractAddress)) {
            $enabled = false;
        }

        if ($enabled) {
            $instructions = $request->exists('instructions')
                ? $validated['instructions']
                : $settings?->instructions;
            $recipientName = $request->exists('recipient_name')
                ? $validated['recipient_name']
                : $settings?->recipient_name;
            $minimumAmount = $request->exists('minimum_amount')
                ? $validated['minimum_amount']
                : (string) ($settings?->minimum_amount ?? '0.00000000');
            $hasQr = $request->hasFile('qr_image')
                || (! $request->boolean('remove_qr')
                    && DepositSettings::isManagedQrPath($settings?->qr_path)
                    && Storage::disk('local')->exists($settings->qr_path));
            $hasLogo = $request->hasFile('logo_image')
                || (! $request->boolean('remove_logo')
                    && DepositSettings::isManagedLogoPath($settings?->logo_path)
                    && Storage::disk('local')->exists($settings->logo_path));
            $errors = [];

            if (! is_string($receivingAddress) || preg_match('/\A0x[a-fA-F0-9]{40}\z/', $receivingAddress) !== 1) {
                $errors['receiving_wallet_address'] = 'Enter a valid BEP20 receiving wallet address before enabling deposits.';
            }
            if (! is_string($recipientName) || trim($recipientName) === '') {
                $errors['recipient_name'] = 'A payment recipient name is required before deposits can be enabled.';
            }
            if (! is_string($instructions) || trim($instructions) === '') {
                $errors['instructions'] = 'Deposit instructions are required before deposits can be enabled.';
            }
            if (! is_string($minimumAmount) || Decimal::compare($minimumAmount, '0', 8) <= 0) {
                $errors['minimum_amount'] = 'The minimum deposit must be greater than zero before deposits can be enabled.';
            }
            if (! $hasQr) {
                $errors['qr_image'] = 'Upload a deposit QR image before enabling deposits.';
            }
            if (! $hasLogo) {
                $errors['logo_image'] = 'Upload a network logo before enabling deposits.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        if ($request->boolean('remove_logo') && $request->hasFile('logo_image')) {
            return back()->withInput()->withErrors([
                'logo_image' => 'Choose either a replacement logo or remove the current logo.',
            ]);
        }

        if ($request->boolean('remove_qr') && $request->hasFile('qr_image')) {
            return back()->withInput()->withErrors([
                'qr_image' => 'Choose either a replacement QR image or remove the current image.',
            ]);
        }

        $newQrPath = null;
        $newLogoPath = null;
        $imagesChanged = false;
        $oldQrPath = null;
        $oldLogoPath = null;
        try {
            if ($request->hasFile('qr_image')) {
                $newQrPath = $request->file('qr_image')->store('deposits/qr', 'local');
                if (! is_string($newQrPath) || $newQrPath === '') {
                    throw new RuntimeException('The payment QR image could not be stored.');
                }
            }

            if ($request->hasFile('logo_image')) {
                $newLogoPath = $request->file('logo_image')->store('deposits/logos', 'local');
                if (! is_string($newLogoPath) || $newLogoPath === '') {
                    throw new RuntimeException('The network logo could not be stored.');
                }
            }

            DB::transaction(function () use ($request, $validated, $enabled, $newQrPath, $newLogoPath, &$oldQrPath, &$oldLogoPath, &$imagesChanged, &$settings): void {
                $settings = DepositSettings::query()->lockForUpdate()->find(1);
                $creating = ! $settings;
                if (! $settings) {
                    $settings = new DepositSettings(['id' => 1]);
                }

                $oldQrPath = $settings->qr_path;
                $oldLogoPath = $settings->logo_path;
                $imagesChanged = $newQrPath !== null
                    || $newLogoPath !== null
                    || ($request->boolean('remove_qr') && $oldQrPath !== null)
                    || ($request->boolean('remove_logo') && $oldLogoPath !== null);
                $before = [
                    'recipient_name' => $settings->recipient_name,
                    'instructions' => $settings->instructions,
                    'network_name' => $settings->network_name,
                    'blockchain' => $settings->blockchain,
                    'token_contract_address' => $settings->token_contract_address,
                    'receiving_wallet_address' => $settings->receiving_wallet_address,
                    'minimum_amount' => $settings->minimum_amount,
                    'is_enabled' => (bool) $settings->is_enabled,
                    'logo_configured' => (bool) $settings->logo_path,
                    'qr_configured' => (bool) $settings->qr_path,
                ];
                $settingsValues = [
                    'recipient_name' => $request->exists('recipient_name')
                        ? $validated['recipient_name']
                        : $settings->recipient_name,
                    'instructions' => $request->exists('instructions')
                        ? $validated['instructions']
                        : $settings->instructions,
                    'network_name' => $request->exists('network_name')
                        ? $validated['network_name']
                        : ($settings->network_name ?? 'BEP20'),
                    'blockchain' => $request->exists('blockchain')
                        ? $validated['blockchain']
                        : ($settings->blockchain ?? 'BNB Smart Chain'),
                    'token_contract_address' => $request->exists('token_contract_address')
                        ? $validated['token_contract_address']
                        : $settings->token_contract_address,
                    'receiving_wallet_address' => $request->exists('receiving_wallet_address')
                        ? $validated['receiving_wallet_address']
                        : $settings->receiving_wallet_address,
                    'minimum_amount' => $request->exists('minimum_amount')
                        ? $this->normalizeAmount($validated['minimum_amount'])
                        : ($settings->minimum_amount ?? '0.00000000'),
                    'is_enabled' => $enabled,
                    'updated_by_user_id' => $request->user('admin')->id,
                    'qr_path' => $request->boolean('remove_qr')
                        ? null
                        : ($newQrPath ?? $settings->qr_path),
                    'logo_path' => $request->boolean('remove_logo')
                        ? null
                        : ($newLogoPath ?? $settings->logo_path),
                ];
                $settings->fill($settingsValues);
                try {
                    if (! $settings->save()) {
                        throw new RuntimeException('The deposit settings model declined the save operation.');
                    }
                } catch (UniqueConstraintViolationException $exception) {
                    if (! $creating) {
                        throw $exception;
                    }

                    $settings = DepositSettings::query()->lockForUpdate()->find(1);
                    if (! $settings) {
                        throw $exception;
                    }

                    $oldQrPath = $settings->qr_path;
                    $oldLogoPath = $settings->logo_path;
                    $imagesChanged = $newQrPath !== null
                        || $newLogoPath !== null
                        || ($request->boolean('remove_qr') && $oldQrPath !== null)
                        || ($request->boolean('remove_logo') && $oldLogoPath !== null);
                    $before = [
                        'recipient_name' => $settings->recipient_name,
                        'instructions' => $settings->instructions,
                        'network_name' => $settings->network_name,
                        'blockchain' => $settings->blockchain,
                        'token_contract_address' => $settings->token_contract_address,
                        'receiving_wallet_address' => $settings->receiving_wallet_address,
                        'minimum_amount' => $settings->minimum_amount,
                        'is_enabled' => (bool) $settings->is_enabled,
                        'logo_configured' => (bool) $settings->logo_path,
                        'qr_configured' => (bool) $settings->qr_path,
                    ];
                    $settings->fill($settingsValues);
                    if (! $settings->save()) {
                        throw new RuntimeException('The deposit settings model declined the save operation.');
                    }
                }

                $settings->refresh();
                foreach ($settingsValues as $attribute => $expectedValue) {
                    if (! DepositSettingsPersistence::matches($attribute, $settings->getAttribute($attribute), $expectedValue)) {
                        throw new RuntimeException('The saved deposit settings could not be verified for '.$attribute.'.');
                    }
                }

                AuditLog::create([
                    'actor_user_id' => $request->user('admin')->id,
                    'subject_user_id' => $request->user('admin')->id,
                    'event' => 'admin.deposit_settings_updated',
                    'auditable_type' => DepositSettings::class,
                    'auditable_id' => $settings->id,
                    'metadata' => [
                        'before' => $before,
                        'after' => [
                            'recipient_name' => $settings->recipient_name,
                            'instructions' => $settings->instructions,
                            'network_name' => $settings->network_name,
                            'blockchain' => $settings->blockchain,
                            'token_contract_address' => $settings->token_contract_address,
                            'receiving_wallet_address' => $settings->receiving_wallet_address,
                            'minimum_amount' => $settings->minimum_amount,
                            'is_enabled' => (bool) $settings->is_enabled,
                            'logo_configured' => (bool) $settings->logo_path,
                            'qr_configured' => (bool) $settings->qr_path,
                        ],
                    ],
                    'ip_address' => $request->ip(),
                ]);

                if ($imagesChanged) {
                    AuditLog::create([
                        'actor_user_id' => $request->user('admin')->id,
                        'subject_user_id' => $request->user('admin')->id,
                        'event' => 'admin.deposit_qr_updated',
                        'auditable_type' => DepositSettings::class,
                        'auditable_id' => $settings->id,
                        'metadata' => [
                            'qr_action' => $newQrPath ? 'replaced' : ($request->boolean('remove_qr') ? 'removed' : 'unchanged'),
                            'logo_action' => $newLogoPath ? 'replaced' : ($request->boolean('remove_logo') ? 'removed' : 'unchanged'),
                        ],
                        'ip_address' => $request->ip(),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            $this->logDepositSettingsFailure($exception);

            foreach ([[$newQrPath, 'qr'], [$newLogoPath, 'logo']] as [$newPath, $kind]) {
                if (! $newPath) {
                    continue;
                }

                try {
                    $this->deleteManagedImage($newPath, $kind);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            return $this->failedUpdateResponse($request, DepositSettingsPersistence::adminMessage($exception));
        }

        foreach ([[$oldQrPath, $settings?->qr_path, 'qr'], [$oldLogoPath, $settings?->logo_path, 'logo']] as [$oldPath, $currentPath, $kind]) {
            if (! $oldPath || $oldPath === $currentPath) {
                continue;
            }

            try {
                $this->deleteManagedImage($oldPath, $kind);
            } catch (RuntimeException $exception) {
                report($exception);

                return back()->with('error', 'Settings were saved, but an obsolete deposit image could not be removed.');
            }
        }

        return back()->with('status', 'Deposit settings updated.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $deleted = false;
        $oldPaths = DB::transaction(function () use ($request, &$deleted): array {
            $settings = DepositSettings::query()->lockForUpdate()->find(1);
            if (! $settings) {
                return [];
            }

            AuditLog::create([
                'actor_user_id' => $request->user('admin')->id,
                'subject_user_id' => $request->user('admin')->id,
                'event' => 'admin.deposit_settings_deleted',
                'auditable_type' => DepositSettings::class,
                'auditable_id' => $settings->id,
                'metadata' => [
                    'qr_configured' => (bool) $settings->qr_path,
                    'recipient_configured' => (bool) $settings->recipient_name,
                    'instructions_configured' => (bool) $settings->instructions,
                ],
                'ip_address' => $request->ip(),
            ]);

            $paths = [$settings->qr_path, $settings->logo_path];
            $settings->delete();
            $deleted = true;

            return $paths;
        });

        if (! $deleted) {
            return back()->with('error', 'No deposit settings were available to delete.');
        }

        foreach ($oldPaths as $path) {
            if (! $path) {
                continue;
            }

            try {
                $kind = DepositSettings::isManagedLogoPath($path) ? 'logo' : 'qr';
                $this->deleteManagedImage($path, $kind);
            } catch (RuntimeException $exception) {
                report($exception);

                return back()->with('error', 'Settings were deleted, but a deposit image could not be removed.');
            }
        }

        return back()->with('status', 'Deposit settings deleted.');
    }

    public function qr(): StreamedResponse
    {
        $path = DepositSettings::query()->find(1)?->qr_path;
        abort_unless(DepositSettings::isManagedQrPath($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function logo(): StreamedResponse
    {
        $path = DepositSettings::query()->find(1)?->logo_path;
        abort_unless(DepositSettings::isManagedLogoPath($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function failedUpdateResponse(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 500);
        }

        return back()->withInput()->with('error', $message);
    }

    private function logDepositSettingsFailure(Throwable $exception): void
    {
        $context = [
            'exception' => $exception::class,
        ];

        if ($exception instanceof QueryException) {
            $context['sqlstate'] = $exception->errorInfo[0] ?? null;
            $context['driver_code'] = $exception->errorInfo[1] ?? null;
        }

        Log::error('Deposit settings update failed.', $context);
        report($exception);
    }

    private function deleteManagedImage(string $path, string $kind): void
    {
        $isManaged = $kind === 'logo'
            ? DepositSettings::isManagedLogoPath($path)
            : DepositSettings::isManagedQrPath($path);
        if (! $isManaged) {
            throw new RuntimeException('The stored deposit image path is outside its managed directory.');
        }

        if (! Storage::disk('local')->exists($path)) {
            return;
        }

        if (! Storage::disk('local')->delete($path)) {
            throw new RuntimeException('The deposit image could not be removed.');
        }
    }

    private function tokenContractIsVerified(mixed $contractAddress): bool
    {
        $trustedContractAddress = config('services.bep20.usdt_contract_address');

        return is_string($trustedContractAddress)
            && preg_match('/\A0x[a-fA-F0-9]{40}\z/', trim($trustedContractAddress)) === 1
            && is_string($contractAddress)
            && preg_match('/\A0x[a-fA-F0-9]{40}\z/', $contractAddress) === 1
            && hash_equals(strtolower(trim($trustedContractAddress)), strtolower($contractAddress));
    }

    private function normalizeAmount(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 8, '0');
    }
}

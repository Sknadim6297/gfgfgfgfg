<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DepositSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use Illuminate\View\View;

class AdminDepositSettingsController extends Controller
{
    public function edit(): View
    {
        $settings = DepositSettings::query()->find(1);
        $qrPath = $settings?->qr_path;

        return view('admin.deposits.settings', [
            'settings' => $settings,
            'qrImageAvailable' => DepositSettings::isManagedQrPath($qrPath) && Storage::disk('local')->exists($qrPath),
            'history' => AuditLog::query()
                ->whereIn('event', ['admin.deposit_settings_updated', 'admin.deposit_qr_updated', 'admin.deposit_settings_deleted'])
                ->with('actor')
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'qr_image' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'remove_qr' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('remove_qr') && $request->hasFile('qr_image')) {
            return back()->withInput()->withErrors([
                'qr_image' => 'Choose either a replacement QR image or remove the current image.',
            ]);
        }

        $newPath = null;
        if ($request->hasFile('qr_image')) {
            $newPath = $request->file('qr_image')->store('deposits/qr', 'local');
            if (! $newPath) {
                throw new RuntimeException('The payment QR image could not be stored.');
            }
        }

        $qrChanged = false;
        $oldPath = null;
        try {
            DB::transaction(function () use ($request, $validated, $newPath, &$oldPath, &$qrChanged, &$settings): void {
                $settings = DepositSettings::query()->lockForUpdate()->find(1);
                if (! $settings) {
                    $settings = DepositSettings::create(['id' => 1]);
                }

                $oldPath = $settings->qr_path;
                $qrChanged = $newPath !== null || ($request->boolean('remove_qr') && $oldPath !== null);
                $before = [
                    'recipient_name' => $settings->recipient_name,
                    'instructions' => $settings->instructions,
                    'qr_configured' => (bool) $settings->qr_path,
                ];
                $settings->fill([
                    'recipient_name' => array_key_exists('recipient_name', $validated)
                        ? $validated['recipient_name']
                        : $settings->recipient_name,
                    'instructions' => array_key_exists('instructions', $validated)
                        ? $validated['instructions']
                        : $settings->instructions,
                    'updated_by_user_id' => $request->user('admin')->id,
                    'qr_path' => $request->boolean('remove_qr')
                        ? null
                        : ($newPath ?? $settings->qr_path),
                ])->save();

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
                            'qr_configured' => (bool) $settings->qr_path,
                        ],
                    ],
                    'ip_address' => $request->ip(),
                ]);

                if ($qrChanged) {
                    AuditLog::create([
                        'actor_user_id' => $request->user('admin')->id,
                        'subject_user_id' => $request->user('admin')->id,
                        'event' => 'admin.deposit_qr_updated',
                        'auditable_type' => DepositSettings::class,
                        'auditable_id' => $settings->id,
                        'metadata' => ['action' => $request->boolean('remove_qr') ? 'removed' : 'replaced'],
                        'ip_address' => $request->ip(),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                try {
                    $this->deleteQrFile($newPath);
                } catch (Throwable $cleanupException) {
                    throw new RuntimeException('The new payment QR image could not be removed after the settings update failed.', previous: $cleanupException);
                }
            }

            throw $exception;
        }

        if ($oldPath && $oldPath !== $settings?->qr_path) {
            try {
                $this->deleteQrFile($oldPath);
            } catch (RuntimeException $exception) {
                report($exception);

                return back()->with('error', 'Settings were saved, but the obsolete QR image could not be removed.');
            }
        }

        return back()->with('status', 'Deposit settings updated.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $deleted = false;
        $oldPath = DB::transaction(function () use ($request, &$deleted): ?string {
            $settings = DepositSettings::query()->lockForUpdate()->find(1);
            if (! $settings) {
                return null;
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

            $path = $settings->qr_path;
            $settings->delete();
            $deleted = true;

            return $path;
        });

        if (! $deleted) {
            return back()->with('error', 'No deposit settings were available to delete.');
        }

        if ($oldPath) {
            try {
                $this->deleteQrFile($oldPath);
            } catch (RuntimeException $exception) {
                report($exception);

                return back()->with('error', 'Settings were deleted, but the QR image could not be removed.');
            }
        }

        return back()->with('status', 'Deposit settings deleted.');
    }

    public function qr(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $path = DepositSettings::query()->find(1)?->qr_path;
        abort_unless(DepositSettings::isManagedQrPath($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function deleteQrFile(string $path): void
    {
        if (! DepositSettings::isManagedQrPath($path)) {
            throw new RuntimeException('The stored payment QR path is outside the managed QR directory.');
        }

        if (! Storage::disk('local')->exists($path)) {
            return;
        }

        if (! Storage::disk('local')->delete($path)) {
            throw new RuntimeException('The payment QR image could not be removed.');
        }
    }
}

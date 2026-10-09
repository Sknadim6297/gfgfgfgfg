<?php

namespace App\Http\Controllers;

use App\Jobs\BuildFcmNotificationCampaign;
use Illuminate\Database\Eloquent\Builder;
use App\Models\FcmNotificationCampaign;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminPushNotificationController extends Controller
{
    public function index(): View
    {
        $customers = User::query()
            ->where('role', 'user')
            ->where('account_status', 'active')
            ->whereHas('fcmDeviceTokens')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $campaigns = FcmNotificationCampaign::query()
            ->with(['admin:id,name', 'targetUser:id,name,email'])
            ->withCount(['deliveries as pending_count' => fn ($query) => $query->whereIn('status', ['pending', 'processing'])])
            ->latest('id')
            ->paginate(15);

        return view('admin.push-notifications.index', compact('customers', 'campaigns'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:80'],
            'message' => ['required', 'string', 'max:300'],
            'audience' => ['required', Rule::in(['all_subscribed', 'specific_user'])],
            'target_user_id' => ['nullable', 'required_if:audience,specific_user', 'prohibited_unless:audience,specific_user', 'integer', 'exists:users,id'],
            'destination_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $destination = $request->input('destination_url');
            if (is_string($destination) && $destination !== '' && ! $this->isSameOriginDestination($destination)) {
                $validator->errors()->add('destination_url', 'Enter a relative path or a URL on this website.');
            }

            $title = $request->input('title');
            $message = $request->input('message');
            $payloadBytes = strlen(is_string($title) ? $title : '')
                + strlen(is_string($message) ? $message : '')
                + strlen(is_string($destination) ? $destination : '')
                + 512;
            if ($payloadBytes > 4096) {
                $validator->errors()->add('message', 'The combined notification content is too long to send.');
            }
        });

        $validated = $validator->validate();
        $targetUser = null;

        if ($validated['audience'] === 'specific_user') {
            $targetUser = User::query()
                ->whereKey($validated['target_user_id'])
                ->where('role', 'user')
                ->where('account_status', 'active')
                ->whereHas('fcmDeviceTokens')
                ->first();

            if (! $targetUser) {
                return back()->withInput()->withErrors([
                    'target_user_id' => 'Select an active customer with a subscribed device.',
                ]);
            }
        } elseif (! $this->subscribedCustomers()->exists()) {
            return back()->withInput()->withErrors([
                'audience' => 'No active customers have subscribed to push notifications yet.',
            ]);
        }

        $admin = $request->user('admin');
        $campaign = FcmNotificationCampaign::create([
            'admin_id' => $admin->id,
            'target_user_id' => $targetUser?->id,
            'audience' => $validated['audience'],
            'title' => $validated['title'],
            'message' => $validated['message'],
            'destination_url' => ($validated['destination_url'] ?? '') ?: null,
            'status' => 'queued',
        ]);
        BuildFcmNotificationCampaign::dispatch($campaign->id)->onConnection('database');

        return redirect()->route('admin.push-notifications.index')
            ->with('status', 'Notification campaign queued for processing.');
    }

    private function isSameOriginDestination(string $destination): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $destination) === 1) {
            return false;
        }

        $parts = parse_url($destination);
        if (! is_array($parts)) {
            return false;
        }

        if (str_starts_with($destination, '/')) {
            return ! str_starts_with($destination, '//')
                && ! isset($parts['host'])
                && ! isset($parts['user'])
                && ! isset($parts['pass']);
        }

        $configured = parse_url((string) config('app.url'));
        if (! is_array($configured) || ! isset($parts['scheme'], $parts['host'], $configured['scheme'], $configured['host'])) {
            return false;
        }

        return strtolower($parts['scheme']) === strtolower($configured['scheme'])
            && strtolower($parts['host']) === strtolower($configured['host'])
            && ($parts['port'] ?? null) === ($configured['port'] ?? null)
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function subscribedCustomers(): Builder
    {
        return User::query()
            ->where('role', 'user')
            ->where('account_status', 'active')
            ->whereHas('fcmDeviceTokens');
    }
}

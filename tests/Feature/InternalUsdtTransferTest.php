<?php

namespace Tests\Feature;

use App\Models\BalanceLedgerEntry;
use App\Models\Deposit;
use App\Models\ExchangeRequest;
use App\Models\InternalTransferSettings;
use App\Models\InternalUsdtTransfer;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class InternalUsdtTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_transfers_available_usdt_and_both_parties_see_history(): void
    {
        $sender = $this->member(['balance' => '25.50000000', 'inr_balance' => '80.00', 'demo_usdt_balance' => '4.00000000']);
        $recipient = $this->member(['balance' => '1.00000000', 'name' => 'Recipient Member']);
        $this->enableTransfers();
        $key = (string) Str::uuid();

        $this->actingAs($sender, 'web')
            ->post(route('wallet.transfers.preview'), ['recipient_customer_id' => strtolower($recipient->customer_id)])
            ->assertRedirect()
            ->assertSessionHas('recipient_preview.customer_id', $recipient->customer_id);

        $this->get(route('wallet.transfers.index'))
            ->assertOk()
            ->assertSee('Recipient Member')
            ->assertSee($recipient->customer_id)
            ->assertDontSee($recipient->email);

        $this->post(route('wallet.transfers.store'), $this->payload($recipient, '2.50000000', $key))
            ->assertRedirect(route('wallet.transfers.index'))
            ->assertSessionHas('status');

        $transfer = InternalUsdtTransfer::query()->firstOrFail();
        $this->assertSame('USDT', $transfer->currency);
        $this->assertSame('completed', $transfer->status);
        $this->assertSame('23.00000000', $sender->fresh()->balance);
        $this->assertSame('3.50000000', $recipient->fresh()->balance);
        $this->assertSame('80.00', $sender->fresh()->inr_balance);
        $this->assertSame('4.00000000', $sender->fresh()->demo_usdt_balance);
        $this->assertSame('0.00', $recipient->fresh()->inr_balance);
        $this->assertDatabaseHas('balance_ledger_entries', [
            'user_id' => $sender->id,
            'source_type' => 'internal_usdt_transfer',
            'source_id' => $transfer->id,
            'entry_type' => 'debit',
            'amount' => '2.50000000',
        ]);
        $this->assertDatabaseHas('balance_ledger_entries', [
            'user_id' => $recipient->id,
            'source_type' => 'internal_usdt_transfer',
            'source_id' => $transfer->id,
            'entry_type' => 'credit',
            'amount' => '2.50000000',
        ]);

        $this->get(route('wallet.transfers.index'))
            ->assertOk()
            ->assertSee($transfer->transfer_reference)
            ->assertSee('Sent');
        $this->actingAs($recipient, 'web')
            ->get(route('wallet.transfers.index'))
            ->assertOk()
            ->assertSee($transfer->transfer_reference)
            ->assertSee('Received');

        $this->expectException(LogicException::class);
        $transfer->status = 'reversed';
        $transfer->save();
    }

    public function test_transfer_rejects_insufficient_balance_invalid_pin_self_transfer_and_blocked_recipient(): void
    {
        $sender = $this->member(['balance' => '5.00000000']);
        $recipient = $this->member();
        $blocked = $this->member(['account_status' => 'blocked']);
        $suspended = $this->member(['account_status' => 'suspended']);
        $this->enableTransfers();
        $this->actingAs($sender, 'web');

        $this->post(route('wallet.transfers.store'), $this->payload($recipient, '5.00000001'))
            ->assertSessionHasErrors('amount');
        $this->post(route('wallet.transfers.store'), $this->payload($recipient, '1.00000000', pin: '0000'))
            ->assertSessionHasErrors('wallet_transaction_pin');
        $this->post(route('wallet.transfers.store'), $this->payload($sender, '1.00000000'))
            ->assertSessionHasErrors('recipient_customer_id');
        $this->post(route('wallet.transfers.store'), $this->payload($blocked, '1.00000000'))
            ->assertSessionHasErrors('recipient_customer_id');
        $this->post(route('wallet.transfers.store'), $this->payload($suspended, '1.00000000'))
            ->assertSessionHasErrors('recipient_customer_id');
        $this->post(route('wallet.transfers.store'), [
            ...$this->payload($recipient, '1.00000000'),
            'currency' => 'INR',
        ])->assertSessionHasErrors('currency');

        $this->assertSame('5.00000000', $sender->fresh()->balance);
        $this->assertSame(0, InternalUsdtTransfer::query()->count());
        $this->assertSame(0, BalanceLedgerEntry::query()->where('source_type', 'internal_usdt_transfer')->count());
    }

    public function test_duplicate_submission_does_not_move_funds_twice_and_a_second_transfer_cannot_overdraw(): void
    {
        $sender = $this->member(['balance' => '10.00000000']);
        $recipient = $this->member(['balance' => '0.00000000']);
        $this->enableTransfers();
        $key = (string) Str::uuid();
        $this->actingAs($sender, 'web');

        $this->post(route('wallet.transfers.store'), $this->payload($recipient, '6.00000000', $key))->assertRedirect();
        $this->post(route('wallet.transfers.store'), $this->payload($recipient, '6.00000000', $key))->assertRedirect();
        $this->post(route('wallet.transfers.store'), $this->payload($recipient, '7.00000000', $key))
            ->assertSessionHasErrors('submission_key');

        $this->assertSame(1, InternalUsdtTransfer::query()->count());
        $this->assertSame('4.00000000', $sender->fresh()->balance);
        $this->assertSame('6.00000000', $recipient->fresh()->balance);
        $this->assertSame(2, BalanceLedgerEntry::query()->where('source_type', 'internal_usdt_transfer')->count());

        $this->post(route('wallet.transfers.store'), $this->payload($recipient, '6.00000000'))
            ->assertSessionHasErrors('amount');
        $this->assertSame('4.00000000', $sender->fresh()->balance);
    }

    public function test_failed_credit_rolls_back_the_sender_debit(): void
    {
        $sender = $this->member(['balance' => '8.00000000']);
        $recipient = $this->member(['balance' => '1.25000000']);
        $this->enableTransfers();
        $created = 0;
        Event::listen('eloquent.created: '.BalanceLedgerEntry::class, function () use (&$created): void {
            $created++;
            if ($created === 2) {
                throw new \RuntimeException('Recipient credit failed.');
            }
        });

        try {
            $this->actingAs($sender, 'web')
                ->from(route('wallet.transfers.index'))
                ->post(route('wallet.transfers.store'), $this->payload($recipient, '3.00000000'))
                ->assertRedirect(route('wallet.transfers.index'))
                ->assertSessionHas('error', 'The transfer could not be completed. Please try again.');
        } finally {
            Event::forget('eloquent.created: '.BalanceLedgerEntry::class);
        }

        $this->assertSame('8.00000000', $sender->fresh()->balance);
        $this->assertSame('1.25000000', $recipient->fresh()->balance);
        $this->assertSame(0, InternalUsdtTransfer::query()->count());
        $this->assertSame(0, BalanceLedgerEntry::query()->count());
    }

    public function test_pending_deposits_and_sell_holds_are_not_transferable(): void
    {
        $sender = $this->member(['balance' => '10.00000000']);
        $recipient = $this->member();
        $this->enableTransfers();
        Deposit::create([
            'deposit_id' => 'REF-PENDING-1',
            'user_id' => $sender->id,
            'submission_key' => (string) Str::uuid(),
            'amount' => '100.00000000',
            'transaction_reference' => 'PENDING-DEPOSIT-1',
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        ExchangeRequest::create([
            'user_id' => $sender->id,
            'usdt_amount' => '4.00000000',
            'exchange_rate' => '90.00000000',
            'inr_amount' => '360.00',
            'available_usd_before' => '10.00000000',
            'status' => 'pending',
        ]);
        $this->actingAs($sender, 'web');

        $this->post(route('wallet.transfers.store'), $this->payload($recipient, '7.00000000'))
            ->assertSessionHasErrors('amount');
        $this->post(route('wallet.transfers.store'), $this->payload($recipient, '6.00000000'))
            ->assertRedirect();

        $this->assertSame('4.00000000', $sender->fresh()->balance);
        $this->assertSame(0, Decimal::compare((string) $recipient->fresh()->balance, '6.00000000', 8));
        $this->assertSame('pending', Deposit::query()->firstOrFail()->status);
    }

    public function test_admin_can_configure_limits_and_monitor_transfers_without_editing_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'totp_enabled' => true, 'account_status' => 'active']);
        $sender = $this->member(['balance' => '20.00000000']);
        $recipient = $this->member();
        $this->enableTransfers(['maximum_amount' => '5.00000000']);

        $this->actingAs($sender, 'web')
            ->post(route('wallet.transfers.store'), $this->payload($recipient, '6.00000000'))
            ->assertSessionHasErrors('amount');
        $this->post(route('wallet.transfers.store'), $this->payload($recipient, '5.00000000'))
            ->assertRedirect();
        $transfer = InternalUsdtTransfer::query()->firstOrFail();

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.internal-transfers.index', ['search' => $transfer->transfer_reference, 'status' => 'completed']))
            ->assertOk()
            ->assertSee($transfer->transfer_reference)
            ->assertSee($sender->customer_id)
            ->assertSee($recipient->customer_id)
            ->assertDontSee('Save transfer settings');

        $this->put(route('admin.internal-transfers.settings.update'), [
            'minimum_amount' => '2.00000000',
            'maximum_amount' => '9.00000000',
            'is_enabled' => '0',
        ])->assertRedirect()->assertSessionHas('status', 'Internal transfer settings updated.');

        $settings = InternalTransferSettings::query()->findOrFail(1);
        $this->assertFalse($settings->is_enabled);
        $this->assertSame('2.00000000', $settings->minimum_amount);
        $this->assertSame('9.00000000', $settings->maximum_amount);
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame('5.00000000', (string) $transfer->fresh()->amount);

        auth('admin')->logout();
        $this->actingAs($sender, 'web')
            ->post(route('wallet.transfers.store'), $this->payload($recipient, '2.00000000'))
            ->assertSessionHasErrors('amount');
    }

    public function test_guests_cannot_transfer_and_members_cannot_open_admin_monitoring(): void
    {
        $this->get(route('wallet.transfers.index'))->assertRedirect(route('login'));
        $member = $this->member();
        $this->actingAs($member, 'web')
            ->get(route('admin.internal-transfers.index'))
            ->assertRedirect(route('admin.login'));
    }

    private function enableTransfers(array $overrides = []): void
    {
        InternalTransferSettings::query()->create([
            'id' => 1,
            'minimum_amount' => '1.00000000',
            'maximum_amount' => '100.00000000',
            'is_enabled' => true,
            ...$overrides,
        ]);
    }

    private function member(array $attributes = []): User
    {
        $balance = $attributes['balance'] ?? '0.00000000';
        $inr = $attributes['inr_balance'] ?? '0.00';
        $demo = $attributes['demo_usdt_balance'] ?? '0.00000000';
        unset($attributes['balance'], $attributes['inr_balance'], $attributes['demo_usdt_balance']);

        $user = User::factory()->create($attributes);
        $user->forceFill([
            'balance' => $balance,
            'inr_balance' => $inr,
            'demo_usdt_balance' => $demo,
            'wallet_transaction_password_hash' => Hash::make('1234'),
        ])->save();

        return $user->fresh();
    }

    private function payload(User $recipient, string $amount, ?string $key = null, string $pin = '1234'): array
    {
        return [
            'recipient_customer_id' => $recipient->customer_id,
            'amount' => $amount,
            'wallet_transaction_pin' => $pin,
            'submission_key' => $key ?? (string) Str::uuid(),
            'currency' => 'USDT',
        ];
    }
}

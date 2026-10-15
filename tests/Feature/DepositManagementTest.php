<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BalanceLedgerEntry;
use App\Models\Deposit;
use App\Models\DepositSettings;
use App\Models\ExchangeRate;
use App\Models\User;
use Database\Seeders\ExchangeRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepositManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_bep20_migration_adds_nullable_snapshots_and_preserves_existing_financial_data(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create(['balance' => '21.34000000']);
        $deposit = $this->makeDeposit($customer, '21.34', 'LEGACY-DEPOSIT-001');
        $legacySettings = DepositSettings::create([
            'id' => 1,
            'recipient_name' => 'Existing recipient',
            'instructions' => 'Existing instructions',
        ]);

        $this->assertContains('token_contract_address', Schema::getColumnListing('deposit_settings'));
        $this->assertContains('receiving_wallet_address', Schema::getColumnListing('deposit_settings'));
        $this->assertContains('is_enabled', Schema::getColumnListing('deposit_settings'));
        foreach ([
            'network_name',
            'blockchain',
            'token_contract_address',
            'receiving_wallet_address',
            'recipient_name_snapshot',
            'instructions_snapshot',
            'transaction_hash',
            'transaction_event_index',
            'blockchain_confirmations',
            'blockchain_verified_at',
        ] as $column) {
            $this->assertContains($column, Schema::getColumnListing('deposits'));
        }

        $fractionalDeposit = $this->makeDeposit($customer, '0.12345678', 'EIGHT-DECIMALS-001');
        $this->assertSame('0.12345678', $fractionalDeposit->fresh()->amount);
        $this->assertSame(['pending', 'approved', 'rejected'], Deposit::STATUSES);
        $this->assertSame('Existing recipient', $legacySettings->fresh()->recipient_name);
        $this->assertNull($deposit->fresh()->recipient_name_snapshot);
        $this->assertSame('21.34000000', $customer->fresh()->balance);
        $this->assertSame('21.34000000', $deposit->fresh()->amount);
        $this->assertSame(0, BalanceLedgerEntry::query()->count());
    }

    public function test_admin_can_save_valid_bep20_settings_and_private_logo_and_qr_uploads(): void
    {
        Storage::fake('local');
        config(['services.bep20.usdt_contract_address' => '0x'.str_repeat('a', 40)]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposit-settings.update'), [
                'network_name' => 'BEP20',
                'blockchain' => 'BNB Smart Chain',
                'token_contract_address' => '0x'.str_repeat('a', 40),
                'receiving_wallet_address' => '0x'.str_repeat('b', 40),
                'recipient_name' => 'Quick PayMoney',
                'minimum_amount' => '0.00000001',
                'instructions' => 'Send only USDT using BNB Smart Chain.',
                'is_enabled' => '1',
                'logo_image' => UploadedFile::fake()->image('network.png')->size(120),
                'qr_image' => UploadedFile::fake()->image('deposit-qr.png')->size(120),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit settings updated.');

        $settings = DepositSettings::findOrFail(1);
        $this->assertSame('BEP20', $settings->network_name);
        $this->assertSame('BNB Smart Chain', $settings->blockchain);
        $this->assertSame('0x'.str_repeat('a', 40), $settings->token_contract_address);
        $this->assertSame('0x'.str_repeat('b', 40), $settings->receiving_wallet_address);
        $this->assertSame('0.00000001', $settings->minimum_amount);
        $this->assertTrue($settings->is_enabled);
        Storage::disk('local')->assertExists($settings->logo_path);
        Storage::disk('local')->assertExists($settings->qr_path);
        $this->get(route('admin.deposit-settings.logo'))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.deposit_settings_updated']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.deposit_qr_updated']);
    }

    public function test_admin_is_not_told_settings_were_saved_when_the_model_cancels_persistence(): void
    {
        $admin = $this->admin();
        $event = 'eloquent.saving: '.DepositSettings::class;
        Event::listen($event, static fn (): bool => false);

        try {
            $response = $this->actingAs($admin, 'admin')
                ->withSession(['admin_2fa_verified' => true])
                ->from(route('admin.deposit-settings.edit'))
                ->put(route('admin.deposit-settings.update'), [
                    'network_name' => 'BEP20',
                    'blockchain' => 'BNB Smart Chain',
                    'token_contract_address' => '0x'.str_repeat('a', 40),
                    'receiving_wallet_address' => '0x'.str_repeat('b', 40),
                    'recipient_name' => 'Quick PayMoney',
                    'minimum_amount' => '5.25000000',
                    'instructions' => 'Send only USDT using BNB Smart Chain.',
                    'is_enabled' => '0',
                ]);
        } finally {
            Event::forget($event);
        }

        $response->assertRedirect(route('admin.deposit-settings.edit'))
            ->assertSessionHas('error', 'Deposit settings could not be saved. Please try again.')
            ->assertSessionMissing('status');
        $this->assertDatabaseMissing('deposit_settings', ['id' => 1]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'admin.deposit_settings_updated']);
    }

    public function test_admin_enables_deposits_when_contract_matches_case_insensitively_and_status_is_visible(): void
    {
        Storage::fake('local');
        $trustedContract = '0x55d398326f99059fF775485246999027B3197955';
        config(['services.bep20.usdt_contract_address' => $trustedContract]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposit-settings.update'), [
                'network_name' => 'BEP20',
                'blockchain' => 'BNB Smart Chain',
                'token_contract_address' => strtolower($trustedContract),
                'receiving_wallet_address' => '0x'.str_repeat('b', 40),
                'recipient_name' => 'Quick PayMoney',
                'minimum_amount' => '5.00000000',
                'instructions' => 'Send only USDT using BEP20.',
                'is_enabled' => '1',
                'logo_image' => UploadedFile::fake()->image('network.png'),
                'qr_image' => UploadedFile::fake()->image('deposit-qr.png'),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit settings updated.');

        $this->assertTrue(DepositSettings::findOrFail(1)->is_enabled);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.deposit-settings.edit'))
            ->assertOk()
            ->assertSee('Contract Configuration')
            ->assertSee('Configured')
            ->assertSee('Contract Verification')
            ->assertSee('Matched')
            ->assertSee('Deposit Status')
            ->assertSee('Enabled')
            ->assertSee('Quick PayMoney')
            ->assertSee('Send only USDT using BEP20.');
    }

    public function test_admin_can_remove_network_logo_and_qr_images_without_enabling_deposits(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $settings = $this->configureQr();
        $logoPath = $settings->logo_path;
        $qrPath = $settings->qr_path;
        Storage::disk('local')->put($logoPath, 'logo');
        Storage::disk('local')->put($qrPath, 'qr');

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposit-settings.update'), [
                'is_enabled' => '0',
                'remove_logo' => '1',
                'remove_qr' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit settings updated.');

        $this->assertNull($settings->fresh()->logo_path);
        $this->assertNull($settings->fresh()->qr_path);
        Storage::disk('local')->assertMissing($logoPath);
        Storage::disk('local')->assertMissing($qrPath);
    }

    public function test_admin_deposit_settings_reject_invalid_addresses_and_untrusted_contracts(): void
    {
        Storage::fake('local');
        config(['services.bep20.usdt_contract_address' => '0x'.str_repeat('a', 40)]);
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);

        $this->from(route('admin.deposit-settings.edit'))
            ->put(route('admin.deposit-settings.update'), [
                'token_contract_address' => '0x123',
                'receiving_wallet_address' => 'not-an-address',
                'minimum_amount' => '0.000000001',
                'logo_image' => UploadedFile::fake()->create('wallet.svg', 20, 'image/svg+xml'),
            ])
            ->assertRedirect(route('admin.deposit-settings.edit'))
            ->assertSessionHasErrors([
                'token_contract_address',
                'receiving_wallet_address',
                'minimum_amount',
                'logo_image',
            ]);

        $this->from(route('admin.deposit-settings.edit'))
            ->put(route('admin.deposit-settings.update'), [
                'token_contract_address' => '0x'.str_repeat('c', 40),
                'receiving_wallet_address' => '0x'.str_repeat('b', 40),
                'is_enabled' => '1',
            ])
            ->assertRedirect(route('admin.deposit-settings.edit'))
            ->assertSessionHasErrors('token_contract_address');
        $this->assertDatabaseMissing('deposit_settings', ['id' => 1]);
    }

    public function test_bep20_settings_cannot_be_enabled_without_a_server_trusted_token_contract(): void
    {
        Storage::fake('local');
        config(['services.bep20.usdt_contract_address' => null]);
        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->from(route('admin.deposit-settings.edit'))
            ->put(route('admin.deposit-settings.update'), [
                'token_contract_address' => '0x'.str_repeat('a', 40),
                'is_enabled' => '1',
            ])
            ->assertRedirect(route('admin.deposit-settings.edit'))
            ->assertSessionHasErrors('is_enabled');
        $this->assertDatabaseMissing('deposit_settings', ['id' => 1]);

        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.deposit-settings.edit'))
            ->assertOk()
            ->assertSee('Contract Configuration')
            ->assertSee('Missing')
            ->assertSee('Contract Verification')
            ->assertSee('Not verifiable')
            ->assertSee('Deposit Status')
            ->assertSee('Disabled')
            ->assertSee('Configure it in the server');
    }

    public function test_bep20_settings_cannot_be_enabled_with_incomplete_payment_details(): void
    {
        Storage::fake('local');
        config(['services.bep20.usdt_contract_address' => '0x'.str_repeat('a', 40)]);
        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->from(route('admin.deposit-settings.edit'))
            ->put(route('admin.deposit-settings.update'), [
                'token_contract_address' => '0x'.str_repeat('a', 40),
                'is_enabled' => '1',
            ])
            ->assertRedirect(route('admin.deposit-settings.edit'))
            ->assertSessionHasErrors([
                'receiving_wallet_address',
                'recipient_name',
                'instructions',
                'qr_image',
                'logo_image',
            ]);
        $this->assertDatabaseMissing('deposit_settings', ['id' => 1]);

        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->from(route('admin.deposit-settings.edit'))
            ->put(route('admin.deposit-settings.update'), [
                'token_contract_address' => '0x'.str_repeat('a', 40),
                'receiving_wallet_address' => '0x'.str_repeat('b', 40),
                'recipient_name' => 'Quick PayMoney',
                'instructions' => 'Valid instructions.',
                'minimum_amount' => '0',
                'is_enabled' => '1',
                'logo_image' => UploadedFile::fake()->image('network.png'),
                'qr_image' => UploadedFile::fake()->image('payment.png'),
            ])
            ->assertRedirect(route('admin.deposit-settings.edit'))
            ->assertSessionHasErrors('minimum_amount');
        $this->assertDatabaseMissing('deposit_settings', ['id' => 1]);
    }

    public function test_bep20_settings_reject_an_invalid_server_contract_configuration(): void
    {
        Storage::fake('local');
        config(['services.bep20.usdt_contract_address' => 'not-an-address']);
        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->from(route('admin.deposit-settings.edit'))
            ->put(route('admin.deposit-settings.update'), [
                'token_contract_address' => '0x'.str_repeat('a', 40),
                'is_enabled' => '1',
            ])
            ->assertRedirect(route('admin.deposit-settings.edit'))
            ->assertSessionHasErrors('is_enabled');
        $this->assertDatabaseMissing('deposit_settings', ['id' => 1]);
    }

    public function test_bep20_deposit_settings_remain_protected_by_admin_login_and_two_factor(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        $this->get(route('admin.deposit-settings.edit'))->assertRedirect(route('admin.login'));
        $this->actingAs($admin, 'admin')
            ->get(route('admin.deposit-settings.edit'))
            ->assertRedirect(route('admin.2fa.challenge'));

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.deposit-settings.edit'))
            ->assertOk()
            ->assertSee('BNB Smart Chain')
            ->assertSee('Contract Configuration')
            ->assertSee('Contract Verification')
            ->assertSee('Deposit Status')
            ->assertSee('BEP20_USDT_CONTRACT_ADDRESS');
    }

    public function test_only_authenticated_admins_can_manage_private_payment_qr_settings(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposit-settings.update'), [
                'recipient_name' => 'Quick PayMoney',
                'instructions' => 'Pay using the QR code and retain the UTR.',
                'qr_image' => UploadedFile::fake()->image('payment.png')->size(120),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit settings updated.');

        $settings = DepositSettings::findOrFail(1);
        $this->assertSame('Quick PayMoney', $settings->recipient_name);
        $this->assertSame('Pay using the QR code and retain the UTR.', $settings->instructions);
        Storage::disk('local')->assertExists($settings->qr_path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.deposit_qr_updated']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.deposit_settings_updated']);

        $this->get(route('admin.deposit-settings.qr'))->assertOk();
        $this->get(route('admin.deposit-settings.edit'))->assertOk()
            ->assertSee('Quick PayMoney')
            ->assertSee('Pay using the QR code and retain the UTR.')
            ->assertSee(route('admin.deposit-settings.qr'));

        $customer = User::factory()->create();
        auth('admin')->logout();
        $this->actingAs($customer, 'web')
            ->put(route('admin.deposit-settings.update'), [])
            ->assertRedirect(route('admin.login'));
        $this->assertSame($settings->qr_path, DepositSettings::findOrFail(1)->qr_path);
        $this->delete(route('admin.deposit-settings.destroy'))
            ->assertRedirect(route('admin.login'));
        $this->assertSame($settings->qr_path, DepositSettings::findOrFail(1)->qr_path);

        auth('admin')->logout();
        Storage::fake('local');
        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposit-settings.update'), [
                'recipient_name' => 'Quick PayMoney',
                'instructions' => 'Updated instructions.',
                'remove_qr' => '1',
            ])
            ->assertRedirect();
        $this->assertNull(DepositSettings::findOrFail(1)->qr_path);
    }

    public function test_admin_can_replace_and_delete_payment_settings_without_removing_unrelated_files(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $settings = $this->configureQr();
        Storage::disk('local')->put('deposits/proofs/keep.png', 'private proof');
        $oldQrPath = $settings->qr_path;

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposit-settings.update'), [
                'recipient_name' => 'Updated recipient',
                'instructions' => 'Updated payment steps.',
                'qr_image' => UploadedFile::fake()->image('replacement.webp')->size(180),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit settings updated.');

        $settings->refresh();
        $newQrPath = $settings->qr_path;
        $this->assertNotSame($oldQrPath, $newQrPath);
        $this->assertSame('Updated recipient', $settings->recipient_name);
        $this->assertSame('Updated payment steps.', $settings->instructions);
        Storage::disk('local')->assertMissing($oldQrPath);
        Storage::disk('local')->assertExists($newQrPath);
        Storage::disk('local')->assertExists('deposits/proofs/keep.png');

        $this->get(route('admin.deposit-settings.edit'))
            ->assertOk()
            ->assertSee('Updated recipient')
            ->assertSee('Updated payment steps.')
            ->assertSee(route('admin.deposit-settings.qr'));

        $customer = User::factory()->create();
        $this->actingAs($customer, 'web')
            ->get(route('deposit.create'))
            ->assertOk()
            ->assertSee('Updated payment steps.')
            ->assertSee('Continue')
            ->assertSee(route('deposit.network-logo'));

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);
        $this->delete(route('admin.deposit-settings.destroy'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit settings deleted.');

        $this->assertDatabaseMissing('deposit_settings', ['id' => 1]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.deposit_settings_deleted']);
        Storage::disk('local')->assertMissing($newQrPath);
        Storage::disk('local')->assertExists('deposits/proofs/keep.png');
    }

    public function test_missing_qr_file_shows_saved_details_but_disables_customer_submission(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create(['balance' => '12.00000000']);
        $settings = DepositSettings::create([
            'id' => 1,
            'qr_path' => 'deposits/qr/missing.png',
            'recipient_name' => 'Saved recipient',
            'instructions' => 'Saved payment instructions.',
        ]);

        $this->actingAs($customer, 'web')
            ->get(route('deposit.create'))
            ->assertOk()
            ->assertSee('Saved payment instructions.')
            ->assertSee('BEP20 deposits are not available right now.')
            ->assertDontSee('Continue')
            ->assertDontSee(route('deposit.payment-qr'));

        $this->from(route('deposit.create'))
            ->post(route('deposit.store'), [
                'submission_key' => '35647b60-f6c2-4c67-9f50-2559066cf629',
                'amount' => '25.00',
            ])
            ->assertRedirect(route('deposit.create'))
            ->assertSessionHasErrors('payment');
        $this->assertSame(0, Deposit::query()->count());

        $this->get(route('deposit.payment-qr'))->assertNotFound();

        Storage::disk('local')->put('deposits/proofs/private.png', 'private proof contents');
        $settings->update(['qr_path' => 'deposits/proofs/private.png']);
        $this->get(route('deposit.payment-qr'))
            ->assertNotFound()
            ->assertDontSee('private proof contents');

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.deposit-settings.edit'))
            ->assertOk()
            ->assertSee('The saved QR image is missing or outside the managed QR directory.')
            ->assertSee('Saved recipient');

        $this->assertSame(1, DepositSettings::query()->count());
        $this->assertSame('Saved recipient', $settings->fresh()->recipient_name);
    }

    public function test_customers_can_submit_pending_deposits_idempotently_without_balance_credit(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create();
        $this->configureQr();
        $this->actingAs($customer, 'web');
        $this->assertLessThanOrEqual(40, strlen(Deposit::temporaryId()));

        $this->get(route('deposit.create'))
            ->assertOk()
            ->assertSee('Deposit USDT')
            ->assertSee('Available Balance')
            ->assertSee('Minimum deposit: 5 USDT')
            ->assertSee('Continue')
            ->assertSee('BEP20')
            ->assertDontSee('Submit Transaction ID')
            ->assertSee(route('deposit.network-logo'));
        $this->get(route('deposit.payment-qr'))->assertOk();

        $payload = [
            'submission_key' => '35647b60-f6c2-4c67-9f50-2559066cf629',
            'amount' => '25.50',
        ];

        $response = $this->post(route('deposit.store'), $payload)
            ->assertRedirect();
        $response->assertSessionHas('status', 'Your deposit request is ready. Send USDT only on BEP20, then submit the transaction hash for manual review.');
        $deposit = Deposit::where('user_id', $customer->id)->firstOrFail();
        $response->assertRedirect(route('deposits.show', $deposit));
        $this->assertSame('pending', $deposit->status);
        $this->assertSame('25.50000000', $deposit->amount);
        $this->assertMatchesRegularExpression('/^DEP\d{14}$/', $deposit->deposit_id);
        $this->assertLessThanOrEqual(40, strlen($deposit->deposit_id));
        $this->assertSame('BEP20', $deposit->network_name);
        $this->assertSame('BNB Smart Chain', $deposit->blockchain);
        $this->assertSame('0x'.str_repeat('a', 40), $deposit->token_contract_address);
        $this->assertSame('0x'.str_repeat('b', 40), $deposit->receiving_wallet_address);
        $this->assertSame('Quick PayMoney', $deposit->recipient_name_snapshot);
        $this->assertSame('Pay and keep your transaction reference.', $deposit->instructions_snapshot);
        Storage::disk('local')->assertExists($deposit->network_logo_path);
        Storage::disk('local')->assertExists($deposit->qr_snapshot_path);
        $this->assertSame('0.00000000', $customer->fresh()->balance);
        $this->assertDatabaseMissing('balance_ledger_entries', ['deposit_id' => $deposit->id]);

        $this->post(route('deposit.store'), [
            'submission_key' => $payload['submission_key'],
            'amount' => '999.00',
        ])->assertRedirect(route('deposits.show', $deposit));
        $this->assertSame(1, Deposit::count());
        $this->assertSame(1, AuditLog::where('event', 'deposit.submitted')->count());

        $this->get(route('deposits.show', $deposit))
            ->assertOk()
            ->assertSee($deposit->deposit_id)
            ->assertSee('Copy Address')
            ->assertSee('Submit Transaction ID')
            ->assertSee('Copy Deposit ID')
            ->assertSee('Created Time')
            ->assertDontSee('Cancel Deposit')
            ->assertSee('Quick PayMoney')
            ->assertSee(route('deposits.image', [$deposit, 'qr']))
            ->assertSee('manual review');
        $this->get(route('deposits.image', [$deposit, 'logo']))->assertOk();
        $this->get(route('deposits.image', [$deposit, 'qr']))->assertOk();
    }

    public function test_independent_concurrent_style_deposit_requests_receive_unique_database_backed_ids(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $deposits = [];

        foreach (range(1, 8) as $index) {
            $customer = User::factory()->create();
            $deposit = $this->createCustomerDeposit($customer, '5.00');
            $deposits[] = $deposit;
            $this->assertSame('pending', $deposit->status);
            $this->assertMatchesRegularExpression('/\ADEP\d{14}\z/', $deposit->deposit_id);
            $this->assertLessThanOrEqual(40, strlen($deposit->deposit_id));
        }

        $ids = array_map(static fn (Deposit $deposit): string => $deposit->deposit_id, $deposits);
        $this->assertCount(count($ids), array_unique($ids));
        $this->assertSame(8, Deposit::query()->count());
    }

    public function test_bep20_customer_flow_enforces_enabled_settings_minimum_and_eight_decimal_amounts(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create(['balance' => '12.34567890']);
        $settings = $this->configureQr();
        $this->actingAs($customer, 'web');

        $this->get(route('deposit.create'))
            ->assertOk()
            ->assertSee('12.3456789')
            ->assertSee('USDT')
            ->assertSee(route('deposit.network-logo'));
        $this->from(route('deposit.create'))
            ->post(route('deposit.store'), [
                'submission_key' => (string) Str::uuid(),
                'amount' => '4.99999999',
            ])
            ->assertSessionHasErrors('amount');
        $this->assertSame(0, Deposit::query()->count());

        $this->from(route('deposit.create'))
            ->post(route('deposit.store'), [
                'submission_key' => (string) Str::uuid(),
                'amount' => '5.123456789',
            ])
            ->assertSessionHasErrors('amount');
        $this->from(route('deposit.create'))
            ->post(route('deposit.store'), [
                'submission_key' => (string) Str::uuid(),
                'amount' => '0',
            ])
            ->assertSessionHasErrors('amount');

        $settings->update(['is_enabled' => false]);
        $this->get(route('deposit.create'))
            ->assertOk()
            ->assertSee('BEP20 deposits are not available right now.')
            ->assertDontSee('Continue');
        $this->from(route('deposit.create'))
            ->post(route('deposit.store'), [
                'submission_key' => (string) Str::uuid(),
                'amount' => '10',
            ])
            ->assertSessionHasErrors('payment');
        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_customer_tx_hash_is_validated_claimed_once_and_never_credits_the_wallet(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $customer = User::factory()->create(['balance' => '12.00000000']);
        $first = $this->createCustomerDeposit($customer, '10.12345678');
        $second = $this->createCustomerDeposit($customer, '15.00');
        $this->actingAs($customer, 'web');

        $this->from(route('deposits.show', $first))
            ->post(route('deposits.transaction-hash.store', $first), [
                'submission_key' => $first->submission_key,
                'transaction_hash' => 'not-a-hash',
            ])
            ->assertSessionHasErrors('transaction_hash');

        $hash = '0x'.str_repeat('A', 64);
        $this->post(route('deposits.transaction-hash.store', $first), [
            'submission_key' => $first->submission_key,
            'transaction_hash' => $hash,
        ])
            ->assertRedirect(route('deposits.show', $first))
            ->assertSessionHas('status', 'Transaction hash submitted. It is a payment claim only and requires independent admin verification.');

        $this->assertSame(strtolower($hash), $first->fresh()->transaction_hash);
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('12.00000000', $customer->fresh()->balance);
        $this->assertSame(0, BalanceLedgerEntry::query()->where('user_id', $customer->id)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'deposit.transaction_hash_submitted']);

        $this->post(route('deposits.transaction-hash.store', $first), [
            'submission_key' => $first->submission_key,
            'transaction_hash' => $hash,
        ])->assertRedirect(route('deposits.show', $first));
        $this->assertSame(1, AuditLog::query()->where('event', 'deposit.transaction_hash_submitted')->count());
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.deposits.show', $first))
            ->assertOk()
            ->assertSee($first->deposit_id)
            ->assertSee($customer->name)
            ->assertSee(strtolower($hash));

        $this->from(route('deposits.show', $second))
            ->post(route('deposits.transaction-hash.store', $second), [
                'submission_key' => $second->submission_key,
                'transaction_hash' => strtolower($hash),
            ])
            ->assertSessionHasErrors('transaction_hash');
        $this->assertNull($second->fresh()->transaction_hash);
        $this->assertSame('Submitted', $first->fresh()->customerStatusLabel());
    }

    public function test_customer_deposit_images_and_details_are_private_and_settings_snapshots_are_stable(): void
    {
        Storage::fake('local');
        $settings = $this->configureQr();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deposit = $this->createCustomerDeposit($owner, '25.00');
        $logoSnapshot = $deposit->network_logo_path;
        $qrSnapshot = $deposit->qr_snapshot_path;

        Storage::disk('local')->delete($settings->logo_path);
        Storage::disk('local')->delete($settings->qr_path);
        $settings->update([
            'recipient_name' => 'A changed recipient',
            'instructions' => 'New instructions should not replace the saved snapshot.',
            'receiving_wallet_address' => '0x'.str_repeat('c', 40),
        ]);

        $this->actingAs($owner, 'web')
            ->get(route('deposits.show', $deposit))
            ->assertOk()
            ->assertSee($deposit->receiving_wallet_address)
            ->assertSee('Quick PayMoney')
            ->assertSee('Pay and keep your transaction reference.')
            ->assertDontSee('A changed recipient')
            ->assertDontSee('New instructions should not replace the saved snapshot.');
        Storage::disk('local')->assertExists($logoSnapshot);
        Storage::disk('local')->assertExists($qrSnapshot);
        $this->get(route('deposits.image', [$deposit, 'logo']))->assertOk();
        $this->get(route('deposits.image', [$deposit, 'qr']))->assertOk();

        $this->actingAs($other, 'web')
            ->get(route('deposits.show', $deposit))
            ->assertForbidden();
        $this->get(route('deposits.image', [$deposit, 'logo']))->assertForbidden();
    }

    public function test_bep20_transaction_hash_route_requires_customer_authentication_and_ownership(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deposit = $this->createCustomerDeposit($owner, '7.00');
        $payload = [
            'submission_key' => $deposit->submission_key,
            'transaction_hash' => '0x'.str_repeat('d', 64),
        ];

        auth('web')->logout();
        $this->post(route('deposits.transaction-hash.store', $deposit), $payload)
            ->assertRedirect(route('login'));
        $this->actingAs($other, 'web')
            ->post(route('deposits.transaction-hash.store', $deposit), $payload)
            ->assertForbidden();
        $this->assertNull($deposit->fresh()->transaction_hash);
    }

    public function test_wallet_history_displays_network_transaction_hash_and_customer_status_labels(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $customer = User::factory()->create();
        $deposit = $this->createCustomerDeposit($customer, '5.00000001');
        $deposit->update(['transaction_hash' => '0x'.str_repeat('e', 64)]);

        $this->actingAs($customer, 'web')
            ->get(route('wallet'))
            ->assertOk()
            ->assertSee($deposit->deposit_id)
            ->assertSee('5.00000001 USDT')
            ->assertSee('BEP20')
            ->assertSee('Transaction Hash')
            ->assertSee($deposit->transaction_hash)
            ->assertSee('Submitted')
            ->assertSee(route('deposits.show', $deposit));
    }

    public function test_customer_deposit_form_and_styles_support_mobile_sizing(): void
    {
        $this->assertStringContainsString('@media (max-width: 520px)', file_get_contents(public_path('assets/css/deposit.css')));
        $this->assertStringContainsString('overflow-wrap: anywhere', file_get_contents(public_path('assets/css/deposit.css')));
        $this->assertStringContainsString('min-width: 0', file_get_contents(public_path('assets/css/deposit.css')));
        $this->assertStringContainsString('data-deposit-submit', file_get_contents(resource_path('views/pages/deposit/create.blade.php')));
    }

    public function test_deposit_retries_remain_idempotent_after_the_submission_limit_is_reached(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $customer = User::factory()->create();
        $this->actingAs($customer, 'web');
        $firstPayload = null;

        for ($index = 0; $index < 5; $index++) {
            $payload = [
                'submission_key' => (string) Str::uuid(),
                'amount' => '10.00',
            ];
            $this->post(route('deposit.store'), $payload)->assertRedirect();

            if ($index === 0) {
                $firstPayload = $payload;
            }
        }

        $this->post(route('deposit.store'), [
            'submission_key' => (string) Str::uuid(),
            'amount' => '10.00',
        ])->assertStatus(429)->assertHeader('Retry-After');

        $deposit = Deposit::query()->where('user_id', $customer->id)->orderBy('id')->firstOrFail();
        $this->post(route('deposit.store'), $firstPayload)
            ->assertRedirect(route('deposits.show', $deposit));

        $this->assertSame(5, Deposit::query()->where('user_id', $customer->id)->count());
        $this->assertSame(0, BalanceLedgerEntry::query()->where('user_id', $customer->id)->count());
    }

    public function test_exchange_page_marks_deposit_unavailable_only_when_payment_setup_is_incomplete(): void
    {
        Storage::fake('local');

        $this->get(route('exchange'))
            ->assertOk()
            ->assertSee('aria-disabled="true"', false);

        $this->configureQr();
        $this->get(route('exchange'))
            ->assertOk()
            ->assertDontSee('aria-disabled="true"', false);
    }

    public function test_bep20_admin_approval_requires_login_and_completed_two_factor(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $customer = User::factory()->create();
        $deposit = $this->createBep20Deposit($customer, '10.00', '0x'.str_repeat('a', 64));
        $payload = $this->validBep20ApprovalPayload($deposit);

        $this->put(route('admin.deposits.update', $deposit), $payload)
            ->assertRedirect(route('admin.login'));
        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.deposits.update', $deposit), $payload)
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame('0.00000000', $customer->fresh()->balance);
    }

    public function test_bep20_approval_requires_a_txid_and_all_manual_verification_checks(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $customer = User::factory()->create();
        $withoutHash = $this->createCustomerDeposit($customer, '10.00');
        $withHash = $this->createBep20Deposit($customer, '11.00', '0x'.str_repeat('b', 64));
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->from(route('admin.deposits.show', $withoutHash))
            ->put(route('admin.deposits.update', $withoutHash), $this->validBep20ApprovalPayload($withoutHash))
            ->assertSessionHasErrors('transaction_hash');
        $this->assertSame('pending', $withoutHash->fresh()->status);

        $payload = $this->validBep20ApprovalPayload($withHash);
        unset($payload['verification_checks']['confirmations']);
        $this->from(route('admin.deposits.show', $withHash))
            ->put(route('admin.deposits.update', $withHash), $payload)
            ->assertSessionHasErrors('verification_checks.confirmations');
        $this->assertSame('pending', $withHash->fresh()->status);
        $this->assertSame('0.00000000', $customer->fresh()->balance);
        $this->assertSame(0, BalanceLedgerEntry::query()->where('user_id', $customer->id)->count());
    }

    public function test_bep20_approval_rejects_verified_contract_recipient_and_amount_mismatches(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $customer = User::factory()->create();
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);

        foreach ([
            ['field' => 'verified_token_contract_address', 'value' => '0x'.str_repeat('c', 40), 'error' => 'verified_token_contract_address'],
            ['field' => 'verified_recipient_address', 'value' => '0x'.str_repeat('d', 40), 'error' => 'verified_recipient_address'],
            ['field' => 'verified_amount', 'value' => '10.00000001', 'error' => 'verified_amount'],
        ] as $index => $case) {
            $deposit = $this->createBep20Deposit($customer, '10.00', '0x'.str_repeat(dechex(10 + $index), 64));
            $payload = $this->validBep20ApprovalPayload($deposit);
            $payload[$case['field']] = $case['value'];
            $this->from(route('admin.deposits.show', $deposit))
                ->put(route('admin.deposits.update', $deposit), $payload)
                ->assertSessionHasErrors($case['error']);
            $this->assertSame('pending', $deposit->fresh()->status);
        }

        $this->assertSame('0.00000000', $customer->fresh()->balance);
        $this->assertSame(0, BalanceLedgerEntry::query()->where('user_id', $customer->id)->count());
    }

    public function test_valid_bep20_manual_approval_credits_once_and_records_verification_metadata(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $customer = User::factory()->create(['balance' => '1.25000000']);
        $deposit = $this->createBep20Deposit($customer, '10.12345678', '0x'.str_repeat('e', 64));
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposits.update', $deposit), $this->validBep20ApprovalPayload($deposit))
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit request updated.');

        $this->assertSame('approved', $deposit->fresh()->status);
        $this->assertSame('11.37345678', $customer->fresh()->balance);
        $this->assertSame(1, BalanceLedgerEntry::query()->where('deposit_id', $deposit->id)->count());
        $this->assertSame($admin->id, $deposit->fresh()->reviewed_by_user_id);
        $this->assertNotNull($deposit->fresh()->reviewed_at);
        $this->assertNotNull($deposit->fresh()->blockchain_verified_at);
        $this->assertSame(20, $deposit->fresh()->blockchain_confirmations);
        $this->assertSame(3, $deposit->fresh()->transaction_event_index);
        $audit = AuditLog::query()->where('event', 'admin.deposit_approved')->where('auditable_id', $deposit->id)->firstOrFail();
        $this->assertSame(true, $audit->metadata['manual_verification']['receipt_successful']);
        $this->assertSame('10.12345678', $audit->metadata['manual_verification']['verified_amount']);

        $response = $this->put(route('admin.deposits.update', $deposit), $this->validBep20ApprovalPayload($deposit))
            ->assertRedirect()
            ->assertSessionHas('status', 'This deposit was already processed; no additional balance change was made.');
        $this->assertSame('11.37345678', $customer->fresh()->balance);
        $this->assertSame(1, BalanceLedgerEntry::query()->where('deposit_id', $deposit->id)->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'admin.deposit_approved')->where('auditable_id', $deposit->id)->count());
    }

    public function test_bep20_rejection_requires_a_reason_and_never_credits_balance_or_ledger(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $customer = User::factory()->create(['balance' => '9.00000000']);
        $deposit = $this->createBep20Deposit($customer, '17.00', '0x'.str_repeat('f', 64));
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);

        $this->from(route('admin.deposits.show', $deposit))
            ->put(route('admin.deposits.update', $deposit), ['status' => 'rejected', 'rejection_reason' => ''])
            ->assertSessionHasErrors('rejection_reason');
        $this->assertSame('pending', $deposit->fresh()->status);

        $this->put(route('admin.deposits.update', $deposit), [
            'status' => 'rejected',
            'rejection_reason' => 'The transfer could not be independently verified.',
        ])->assertRedirect()->assertSessionHas('status', 'Deposit request updated.');
        $this->assertSame('rejected', $deposit->fresh()->status);
        $this->assertSame('0x'.str_repeat('f', 64), $deposit->fresh()->transaction_hash);
        $this->assertSame('The transfer could not be independently verified.', $deposit->fresh()->rejection_reason);
        $this->assertSame('9.00000000', $customer->fresh()->balance);
        $this->assertSame(0, BalanceLedgerEntry::query()->where('deposit_id', $deposit->id)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.deposit_rejected', 'auditable_id' => $deposit->id]);
    }

    public function test_bep20_approval_transaction_rolls_back_balance_ledger_status_and_audit_on_failure(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $customer = User::factory()->create(['balance' => '4.00000000']);
        $deposit = $this->createBep20Deposit($customer, '10.00', '0x'.str_repeat('8', 64));
        $admin = $this->admin();
        $event = 'eloquent.creating: '.BalanceLedgerEntry::class;
        \Illuminate\Support\Facades\Event::listen($event, static function (): void {
            throw new \RuntimeException('Simulated ledger storage failure.');
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
                ->put(route('admin.deposits.update', $deposit), $this->validBep20ApprovalPayload($deposit));
            $this->fail('The simulated ledger failure should abort approval.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated ledger storage failure.', $exception->getMessage());
        } finally {
            \Illuminate\Support\Facades\Event::forget($event);
        }

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame('4.00000000', $customer->fresh()->balance);
        $this->assertSame(0, BalanceLedgerEntry::query()->where('deposit_id', $deposit->id)->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'admin.deposit_approved')->where('auditable_id', $deposit->id)->count());
    }

    public function test_admin_deposit_dashboard_totals_include_only_approved_usdt_and_recent_deposits(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create();
        $pending = $this->makeDeposit($customer, '2.50', 'DASH-PENDING-001');
        $approved = $this->makeDeposit($customer, '3.12500001', 'DASH-APPROVED-01');
        $approved->forceFill(['status' => 'approved'])->save();
        $rejected = $this->makeDeposit($customer, '99.00', 'DASH-REJECTED-01');
        $rejected->forceFill(['status' => 'rejected'])->save();

        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Pending verification')
            ->assertSee('Approved USDT')
            ->assertSee('3.12500001')
            ->assertSee($pending->deposit_id)
            ->assertSee($approved->deposit_id)
            ->assertSee($rejected->deposit_id);
    }

    public function test_approval_credits_exactly_once_and_rejection_never_credits(): void
    {
        Storage::fake('local');
        $this->configureQr();
        $customer = User::factory()->create();
        $first = $this->makeDeposit($customer, '50.25', 'UTRAPPROVE001');
        $second = $this->makeDeposit($customer, '18.00', 'UTRREJECT0001');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.deposits.index'))
            ->assertOk()
            ->assertSee($first->deposit_id)
            ->assertSee($customer->name);

        $this->get(route('admin.deposits.show', $first))
            ->assertOk()
            ->assertSee('Verify the payment externally');

        $this->put(route('admin.deposits.update', $first), ['status' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit request updated.');
        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame('50.25000000', $customer->fresh()->balance);
        $this->assertSame(1, BalanceLedgerEntry::where('deposit_id', $first->id)->count());

        $this->put(route('admin.deposits.update', $first), ['status' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('status', 'This deposit was already processed; no additional balance change was made.');
        $this->assertSame('50.25000000', $customer->fresh()->balance);
        $this->assertSame(1, BalanceLedgerEntry::where('deposit_id', $first->id)->count());

        $this->put(route('admin.deposits.update', $second), [
            'status' => 'rejected',
            'rejection_reason' => 'Payment could not be verified externally.',
        ])->assertRedirect();
        $this->assertSame('rejected', $second->fresh()->status);
        $this->assertSame('Payment could not be verified externally.', $second->fresh()->rejection_reason);
        $this->assertSame('50.25000000', $customer->fresh()->balance);
        $this->assertSame(1, BalanceLedgerEntry::where('user_id', $customer->id)->count());

        $this->expectException(\LogicException::class);
        BalanceLedgerEntry::firstOrFail()->update(['amount' => '1.00']);
    }

    public function test_admin_deposit_summary_uses_all_time_usd_amounts_independent_of_list_filters(): void
    {
        $customer = User::factory()->create();
        $this->makeDeposit($customer, '10.10', 'SUM-PENDING-001');
        $this->makeDeposit($customer, '20.20', 'SUM-APPROVED-01')->forceFill(['status' => 'approved'])->save();
        $this->makeDeposit($customer, '30.30', 'SUM-REJECTED-01')->forceFill(['status' => 'rejected'])->save();
        $this->makeDeposit($customer, '40.40', 'SUM-PENDING-002');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.deposits.index', ['status' => 'rejected']))
            ->assertOk()
            ->assertSee('All-time request totals. Only approved amounts count as credited USDT')
            ->assertSee('Total Deposit Requests')
            ->assertSee('Pending Verification')
            ->assertSee('Approved Deposits')
            ->assertSee('Rejected Deposits')
            ->assertSee('Total Approved USDT')
            ->assertSee('20.2 USDT')
            ->assertSee('>4</strong>', false)
            ->assertSee('>2</strong>', false)
            ->assertSee('SUM-REJECTED-01')
            ->assertDontSee('SUM-PENDING-001');
    }

    public function test_empty_deposit_summary_displays_zero_amounts_and_counts(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.deposits.index'))
            ->assertOk()
            ->assertSee('Total Approved USDT')
            ->assertSee('0 USDT')
            ->assertSee('Total Deposit Requests');
    }

    public function test_admin_deposit_audit_history_is_human_readable_escaped_and_preserves_metadata(): void
    {
        $customer = User::factory()->create(['name' => '<img src=x onerror=alert(1)>']);
        $deposit = $this->makeDeposit($customer, '20000.00', 'DFFFE');
        $metadata = [
            'amount' => '20000.00',
            'transaction_reference' => '<script>alert(1)</script>',
            'rejection_reason' => null,
        ];
        $submission = AuditLog::create([
            'actor_user_id' => $customer->id,
            'subject_user_id' => $customer->id,
            'event' => 'deposit.submitted',
            'auditable_type' => Deposit::class,
            'auditable_id' => $deposit->id,
            'metadata' => $metadata,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposits.update', $deposit), ['status' => 'approved'])
            ->assertRedirect();

        $response = $this->get(route('admin.deposits.show', $deposit))
            ->assertOk()
            ->assertSee('Deposit Submitted')
            ->assertSee('Deposit Approved')
            ->assertSee('Customer')
            ->assertSee('$20,000.00')
            ->assertSee('Transaction Reference')
            ->assertSee('Ledger Credit')
            ->assertSee('Recorded')
            ->assertSee(e($customer->name), false)
            ->assertDontSee($customer->name, false)
            ->assertDontSee('<pre class="admin-pre">', false)
            ->assertDontSee('&quot;amount&quot;', false);

        $this->assertSame($metadata, $submission->fresh()->metadata);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.deposit_approved']);
    }

    public function test_admin_deposit_review_renders_bounded_private_asset_previews_and_copyable_payment_values(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('deposits/snapshots/review-logo.png', 'logo image');
        Storage::disk('local')->put('deposits/snapshots/review-qr.png', 'qr image');
        $customer = User::factory()->create();
        $txId = '0x'.str_repeat('abcdef0123456789', 4);
        $deposit = $this->makeDeposit($customer, '125.12345678', 'ADMIN-REVIEW-TX');
        $deposit->update([
            'network_name' => 'BEP20',
            'blockchain' => 'BNB Smart Chain',
            'token_contract_address' => '0x'.str_repeat('a', 40),
            'receiving_wallet_address' => '0x'.str_repeat('b', 40),
            'transaction_hash' => $txId,
            'network_logo_path' => 'deposits/snapshots/review-logo.png',
            'qr_snapshot_path' => 'deposits/snapshots/review-qr.png',
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.deposits.show', $deposit))
            ->assertOk()
            ->assertSee('Original Payment Assets')
            ->assertSee('Network Logo')
            ->assertSee('Payment QR Code')
            ->assertSee('View full QR')
            ->assertSee('admin-deposit-logo-preview', false)
            ->assertSee('admin-deposit-qr-preview', false)
            ->assertSee('data-copy-value="'.$deposit->deposit_id.'"', false)
            ->assertSee('data-copy-value="'.$deposit->token_contract_address.'"', false)
            ->assertSee('data-copy-value="'.$deposit->receiving_wallet_address.'"', false)
            ->assertSee('data-copy-value="'.$txId.'"', false)
            ->assertSee('https://bscscan.com/tx/'.$txId)
            ->assertSee('BNB Smart Chain mainnet · BEP20', false)
            ->assertSee('Rejection reason')
            ->assertSee('Wallet ledger status');

        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="checkbox"[^>]*checked/i', $response->getContent());
        $this->get(route('admin.deposits.image', [$deposit, 'logo']))->assertOk();
        $this->get(route('admin.deposits.image', [$deposit, 'qr']))->assertOk();
    }

    public function test_profile_history_and_proofs_are_private_to_the_owning_customer(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deposit = $this->makeDeposit($owner, '12.00', 'UTRPRIVATE001', 'deposits/proofs/owner.png');
        Storage::disk('local')->put($deposit->proof_path, 'private proof');

        $this->actingAs($owner, 'web')
            ->get(route('wallet'))
            ->assertOk()
            ->assertSee('Deposit History')
            ->assertSee($deposit->deposit_id)
            ->assertSee(route('deposits.show', $deposit))
            ->assertDontSee('Invoice');

        $this->get(route('deposits.show', $deposit))->assertOk();
        $this->get(route('deposits.proof', $deposit))->assertOk();
        $this->get(route('deposits.show', $this->makeDeposit($other, '4.00', 'UTRPRIVATE002')))
            ->assertForbidden();

        auth('web')->logout();
        $this->get(route('deposit.create'))->assertRedirect(route('login'));
        $this->get(route('deposit.payment-qr'))->assertRedirect(route('login'));
    }

    public function test_wallet_deposit_history_search_filters_and_paginates_only_customer_records(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        for ($index = 1; $index <= 11; $index++) {
            $this->makeDeposit(
                $owner,
                '12.00',
                'UTRPROFILE'.str_pad((string) $index, 4, '0', STR_PAD_LEFT)
            );
        }

        $rejected = $this->makeDeposit($owner, '15.00', 'UTRPROFILE9999');
        $rejected->update([
            'status' => 'rejected',
            'rejection_reason' => 'Payment could not be verified.',
        ]);
        $this->makeDeposit($other, '99.00', 'UTRPROFILEOTHER');

        $this->actingAs($owner, 'web')
            ->get(route('wallet', [
                'search' => 'UTRPROFILE',
                'status' => 'pending',
            ]))
            ->assertOk()
            ->assertSee('Deposit ID')
            ->assertSee('Amount (USDT)')
            ->assertSee('Transaction')
            ->assertSee('Date')
            ->assertSee('Details')
            ->assertSee('UTRPROFILE')
            ->assertDontSee('UTRPROFILEOTHER')
            ->assertSee('page=2');

        $this->get(route('wallet', [
            'search' => 'UTRPROFILE9999',
            'status' => 'rejected',
        ]))
            ->assertOk()
            ->assertSee('Payment could not be verified.')
            ->assertSee(route('deposits.show', $rejected))
            ->assertDontSee('UTRPROFILE0001');
    }

    public function test_wallet_shows_actual_usd_and_inr_balances_without_estimated_conversions(): void
    {
        (new ExchangeRateSeeder)->run();
        ExchangeRate::where('plan_key', 'base')->update(['rate' => '100.00000000']);

        $owner = User::factory()->create(['balance' => '35.50']);
        $other = User::factory()->create(['balance' => '999.00']);
        $approved = $this->makeDeposit($owner, '35.50', 'UTRWALLETAPPROVED');
        $approved->update(['status' => 'approved']);
        BalanceLedgerEntry::create([
            'user_id' => $owner->id,
            'deposit_id' => $approved->id,
            'entry_type' => 'credit',
            'amount' => '35.50',
        ]);

        $rejected = $this->makeDeposit($owner, '80.00', 'UTRWALLETREJECTED');
        $rejected->update(['status' => 'rejected']);
        BalanceLedgerEntry::create([
            'user_id' => $owner->id,
            'deposit_id' => $rejected->id,
            'entry_type' => 'credit',
            'amount' => '80.00',
        ]);

        $pending = $this->makeDeposit($owner, '40.00', 'UTRWALLETPENDING');
        BalanceLedgerEntry::create([
            'user_id' => $owner->id,
            'deposit_id' => $pending->id,
            'entry_type' => 'credit',
            'amount' => '40.00',
        ]);

        $otherDeposit = $this->makeDeposit($other, '999.00', 'UTRWALLETOTHER');
        $otherDeposit->update(['status' => 'approved']);
        BalanceLedgerEntry::create([
            'user_id' => $other->id,
            'deposit_id' => $otherDeposit->id,
            'entry_type' => 'credit',
            'amount' => '999.00',
        ]);

        $this->actingAs($owner, 'web')->get(route('wallet'))
            ->assertOk()
            ->assertSee('$35.50')
            ->assertSee('₹0.00')
            ->assertSee('UTRWALLETAPPROVED')
            ->assertSee('UTRWALLETREJECTED')
            ->assertSee('UTRWALLETPENDING')
            ->assertDontSee('UTRWALLETOTHER')
            ->assertDontSee('$999.00')
            ->assertDontSee('₹8,000.00');
    }

    public function test_wallet_requires_an_active_authenticated_customer(): void
    {
        $this->get(route('wallet'))->assertRedirect(route('login'));

        $customer = User::factory()->create(['role' => 'user']);
        $this->actingAs($customer, 'web')->get(route('wallet'))->assertOk();

        $admin = $this->admin();
        $this->actingAs($admin, 'web')->get(route('wallet'))->assertForbidden();
    }

    public function test_recorded_balance_is_customer_specific_and_exchange_uses_shared_header(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create(['balance' => '125.40']);
        $guestExchange = $this->get(route('exchange'))->assertOk()->assertSee(route('login'))
            ->assertSee(route('contact'))
            ->assertSee('Sign in to view')
            ->assertDontSee('$125.40');

        $this->actingAs($customer, 'web')->get(route('exchange'))
            ->assertOk()
            ->assertSee('$125.40')
            ->assertSee('href="'.route('profile.referrals').'" aria-label="Referral earn together"', false)
            ->assertSee('BALANCE')
            ->assertSee(route('wallet'))
            ->assertDontSee(route('logout'))
            ->assertSee(route('deposit.create'))
            ->assertSee('aria-label="Contact support"', false);

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Reset Password')
            ->assertDontSee('Deposit History');
    }

    private function configureQr(): DepositSettings
    {
        Storage::disk('local')->put('deposits/qr/payment.png', 'private qr');
        Storage::disk('local')->put('deposits/logos/bep20.png', 'private logo');
        config(['services.bep20.usdt_contract_address' => '0x'.str_repeat('a', 40)]);

        return DepositSettings::create([
            'id' => 1,
            'qr_path' => 'deposits/qr/payment.png',
            'logo_path' => 'deposits/logos/bep20.png',
            'recipient_name' => 'Quick PayMoney',
            'instructions' => 'Pay and keep your transaction reference.',
            'network_name' => 'BEP20',
            'blockchain' => 'BNB Smart Chain',
            'token_contract_address' => '0x'.str_repeat('a', 40),
            'receiving_wallet_address' => '0x'.str_repeat('b', 40),
            'minimum_amount' => '5.00000000',
            'is_enabled' => true,
        ]);
    }

    private function makeDeposit(User $user, string $amount, string $reference, ?string $proofPath = null): Deposit
    {
        return Deposit::create([
            'deposit_id' => 'REF-'.now()->format('Ymd').'-'.strtoupper(Str::random(12)),
            'user_id' => $user->id,
            'submission_key' => (string) Str::uuid(),
            'amount' => $amount,
            'transaction_reference' => $reference,
            'proof_path' => $proofPath,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
    }

    private function createCustomerDeposit(User $user, string $amount): Deposit
    {
        $submissionKey = (string) Str::uuid();
        $this->actingAs($user, 'web')->withSession(['deposit_submission_key' => $submissionKey]);

        $this->post(route('deposit.store'), [
            'submission_key' => $submissionKey,
            'amount' => $amount,
        ])->assertRedirect();

        return Deposit::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function createBep20Deposit(User $user, string $amount, string $transactionHash): Deposit
    {
        $deposit = $this->createCustomerDeposit($user, $amount);
        $this->actingAs($user, 'web')
            ->post(route('deposits.transaction-hash.store', $deposit), [
                'submission_key' => $deposit->submission_key,
                'transaction_hash' => $transactionHash,
            ])
            ->assertRedirect(route('deposits.show', $deposit));

        return $deposit->fresh();
    }

    private function validBep20ApprovalPayload(Deposit $deposit): array
    {
        return [
            'status' => 'approved',
            'verification_checks' => [
                'network' => '1',
                'receipt' => '1',
                'contract' => '1',
                'recipient' => '1',
                'amount' => '1',
                'confirmations' => '1',
                'unique_transfer' => '1',
            ],
            'verified_network' => 'BEP20',
            'verified_token_contract_address' => $deposit->token_contract_address,
            'verified_recipient_address' => $deposit->receiving_wallet_address,
            'verified_amount' => $deposit->amount,
            'transaction_event_index' => '3',
            'blockchain_confirmations' => '20',
        ];
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'totp_enabled' => true,
        ]);
    }
}

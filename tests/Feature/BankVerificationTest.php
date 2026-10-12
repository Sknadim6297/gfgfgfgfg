<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_submission_is_pending_masked_and_status_is_scoped_to_authenticated_user(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = $this->customerWithBank('verified');

        $this->actingAs($customer, 'web')
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('Not Added')
            ->assertDontSee('998877665544');
        $this->get(route('profile.bank'))
            ->assertOk()
            ->assertSee('Add Bank Details')
            ->assertSee('Save Bank Details')
            ->assertSee('id="bank-details-edit-form"', false)
            ->assertDontSee('id="bank-details-edit-form" hidden', false)
            ->assertDontSee('Edit Bank Details');

        $this->put(route('profile.update'), [
            ...$this->bankDetails(),
            'user_id' => $otherCustomer->id,
            'bank_verification_status' => 'verified',
            'bank_verification_reason' => 'Forged customer status',
            'bank_reviewed_by_user_id' => $customer->id,
        ])->assertRedirect(route('profile.bank'))
            ->assertSessionHas('status', 'Bank details submitted for verification.');

        $customer->refresh();
        $this->assertSame('pending', $customer->bankVerificationStatus());
        $this->assertNull($customer->bank_verification_reason);
        $this->assertNull($customer->bank_reviewed_by_user_id);
        $serializedCustomer = json_encode($customer->toArray());
        $this->assertStringNotContainsString('123456789012', $serializedCustomer);
        $this->assertStringNotContainsString('ABCD0123456', $serializedCustomer);
        $this->assertDatabaseHas('audit_logs', [
            'subject_user_id' => $customer->id,
            'event' => 'user.bank_details_submitted',
        ]);
        $audit = AuditLog::where('subject_user_id', $customer->id)->latest()->firstOrFail();
        $this->assertStringNotContainsString('123456789012', json_encode($audit->metadata));

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Pending')
            ->assertDontSee('123456789012')
            ->assertDontSee('ABCD0123456');
        $this->get(route('profile.bank'))
            ->assertOk()
            ->assertSee('Saved Bank Account')
            ->assertSee('Edit Bank Details')
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('hidden aria-hidden="true"', false)
            ->assertSee('••••••9012')
            ->assertSee('Pending Verification')
            ->assertSee('value="Morgan Example"', false)
            ->assertDontSee('123456789012')
            ->assertDontSee('value="123456789012"', false);
    }

    public function test_customer_edits_to_verified_and_rejected_details_return_to_pending(): void
    {
        $admin = $this->admin();
        $customer = $this->customerWithBank('pending');

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->post(route('admin.users.bank.verify', $customer))
            ->assertRedirect();
        $this->assertSame('verified', $customer->fresh()->bankVerificationStatus());
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.bank_details_verified']);

        $this->actingAs($customer, 'web')
            ->put(route('profile.update'), [
                ...$this->bankDetails(),
                'bank_name' => 'Changed Example Bank',
                'bank_verification_status' => 'verified',
            ])
            ->assertRedirect(route('profile.bank'))
            ->assertSessionHas('status', 'Your bank details were updated and have been sent for verification again.');
        $this->assertSame('pending', $customer->fresh()->bankVerificationStatus());
        $this->assertNull($customer->fresh()->bank_verified_at);
        $this->assertNull($customer->fresh()->bank_reviewed_by_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'subject_user_id' => $customer->id,
            'event' => 'user.bank_details_updated',
        ]);
        $this->get(route('profile.bank'))
            ->assertOk()
            ->assertSee('Changed Example Bank')
            ->assertSee('Pending Verification')
            ->assertSee('Edit Bank Details')
            ->assertSee('hidden aria-hidden="true"', false);

        auth('admin')->logout();
        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->post(route('admin.users.bank.reject', $customer), [
                'reason' => 'Account holder name could not be verified.',
            ])
            ->assertRedirect();
        $this->assertSame('rejected', $customer->fresh()->bankVerificationStatus());

        auth('web')->logout();
        $this->actingAs($customer, 'web')
            ->get(route('profile.bank'))
            ->assertOk()
            ->assertSee('Account holder name could not be verified.');

        $this->put(route('profile.update'), $this->bankDetails())
            ->assertRedirect(route('profile.bank'))
            ->assertSessionHas('status', 'Your bank details were updated and have been sent for verification again.');
        $this->assertSame('pending', $customer->fresh()->bankVerificationStatus());
        $this->assertNull($customer->fresh()->bank_verification_reason);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.bank_details_rejected']);
    }

    public function test_only_two_factor_verified_admin_can_review_customer_bank_details(): void
    {
        $customer = $this->customerWithBank('pending');
        $admin = $this->admin();

        $this->post(route('admin.users.bank.verify', $customer))
            ->assertRedirect(route('admin.login'));
        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.bank.verify', $customer))
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.users.show', $customer))
            ->assertOk()
            ->assertSee('Pending')
            ->assertSee('Morgan Example')
            ->assertSee('Example Bank')
            ->assertSee('123456789012')
            ->assertSee('ABCD0123456')
            ->assertSee('Central Branch')
            ->assertSee('Savings')
            ->assertSee('Full bank account number')
            ->assertSee('Reject bank details');
        $this->get(route('admin.users.index', ['bank_status' => 'pending']))
            ->assertOk()
            ->assertSee($customer->email);

        $this->post(route('admin.users.bank.reject', $customer), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame('pending', $customer->fresh()->bankVerificationStatus());

        $this->post(route('admin.users.bank.verify', $customer))
            ->assertRedirect();
        $this->assertSame('verified', $customer->fresh()->bankVerificationStatus());
        $this->assertNotNull($customer->fresh()->bank_verified_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.bank_details_verified']);

        $anotherAdmin = $this->admin();
        $this->post(route('admin.users.bank.verify', $anotherAdmin))
            ->assertNotFound();
    }

    public function test_full_bank_details_are_not_exposed_to_unauthorized_admin_or_in_customer_directory(): void
    {
        $customer = $this->customerWithBank('pending');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.users.show', $customer))
            ->assertRedirect(route('admin.2fa.challenge'))
            ->assertDontSee('123456789012');

        auth('admin')->logout();
        $this->actingAs(User::factory()->create(), 'web')
            ->get(route('admin.users.show', $customer))
            ->assertRedirect(route('admin.login'))
            ->assertDontSee('123456789012');

        auth('web')->logout();
        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('123456789012')
            ->assertDontSee('ABCD0123456');
    }

    public function test_bank_verification_actions_require_authorized_admin_session(): void
    {
        $customer = $this->customerWithBank('pending');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.bank.verify', $customer))
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->assertSame('pending', $customer->fresh()->bankVerificationStatus());

        $this->withSession(['admin_2fa_verified' => true])
            ->post(route('admin.users.bank.reject', $customer), [
                'reason' => 'Submitted account details could not be verified.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Bank details rejected.');

        $this->assertSame('rejected', $customer->fresh()->bankVerificationStatus());
        $this->assertSame('Submitted account details could not be verified.', $customer->fresh()->bank_verification_reason);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $customer->id,
            'event' => 'admin.bank_details_rejected',
        ]);
    }

    public function test_admin_user_directory_shows_data_error_for_legacy_plaintext_bank_data_without_mutating_it(): void
    {
        $customer = $this->customerWithBank('pending');
        DB::table('users')->where('id', $customer->id)->update([
            'bank_name' => 'Legacy plaintext bank value',
        ]);
        Log::spy();

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Data Error')
            ->assertSee($customer->email);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Unable to decrypt user bank details in the admin directory.', [
                'user_id' => $customer->id,
                'attribute' => 'bank_name',
            ]);
        $this->assertSame('Legacy plaintext bank value', DB::table('users')->where('id', $customer->id)->value('bank_name'));
        $this->expectException(DecryptException::class);
        $customer->refresh()->bank_name;
    }

    public function test_admin_user_directory_shows_data_error_for_corrupt_ciphertext(): void
    {
        $customer = $this->customerWithBank('pending');
        DB::table('users')->where('id', $customer->id)->update([
            'account_number' => 'corrupted-ciphertext',
        ]);
        Log::spy();

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Data Error')
            ->assertSee($customer->email);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Unable to decrypt user bank details in the admin directory.', [
                'user_id' => $customer->id,
                'attribute' => 'account_number',
            ]);
        $this->assertSame('corrupted-ciphertext', DB::table('users')->where('id', $customer->id)->value('account_number'));
    }

    public function test_admin_user_directory_reads_valid_encrypted_bank_details_and_preserves_verification_status(): void
    {
        $customer = $this->customerWithBank('verified');

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Verified')
            ->assertSee($customer->email)
            ->assertDontSee('Data Error');
    }

    public function test_cash_withdrawal_is_available_while_bank_withdrawal_requires_verification(): void
    {
        $pending = $this->customerWithBank('pending', ['inr_balance' => '1000.00']);
        $this->actingAs($pending, 'web')
            ->get(route('wallet'))
            ->assertOk()
            ->assertSee('wallet-withdraw-action', false)
            ->assertSee(route('wallet.withdrawals.create'));
        $this->get(route('wallet.withdrawals.create'))
            ->assertOk()
            ->assertSee('Cash')
            ->assertSee('No bank information required.')
            ->assertSee('Bank Account')
            ->assertSee('Pending verification')
            ->assertSee('disabled', false);

        $this->from(route('wallet'))
            ->post(route('wallet.withdrawals.store'), $this->withdrawalPayload())
            ->assertSessionHasErrors(['bank' => 'Your bank details are pending verification.']);
        $this->assertDatabaseCount('withdrawal_requests', 0);

        $rejected = $this->customerWithBank('rejected', ['inr_balance' => '1000.00']);
        $this->actingAs($rejected, 'web')
            ->post(route('wallet.withdrawals.store'), $this->withdrawalPayload())
            ->assertSessionHasErrors('bank');
        $this->assertDatabaseCount('withdrawal_requests', 0);

        $verified = $this->customerWithBank('verified', ['inr_balance' => '1000.00']);
        $this->actingAs($verified, 'web')
            ->post(route('wallet.withdrawals.store'), $this->withdrawalPayload())
            ->assertRedirect();
        $this->assertSame(1, WithdrawalRequest::where('user_id', $verified->id)->count());

        $verified->forceFill(['bank_verification_status' => 'pending'])->save();
        $this->post(route('wallet.withdrawals.store'), [
            ...$this->withdrawalPayload(),
            'submission_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('bank');
        $this->assertSame(1, WithdrawalRequest::where('user_id', $verified->id)->count());
    }

    public function test_anonymous_customer_cannot_update_bank_details_and_validation_rejects_invalid_values(): void
    {
        $this->put(route('profile.update'), [
            ...$this->bankDetails(),
        ])->assertRedirect(route('login'));

        $customer = User::factory()->create();
        $this->actingAs($customer, 'web')
            ->from(route('profile.bank'))
            ->put(route('profile.update'), [
                ...$this->bankDetails(),
                'account_number' => 'not-an-account',
                'ifsc_code' => 'wrong',
                'account_type' => 'Other',
            ])
            ->assertSessionHasErrors(['account_number', 'ifsc_code', 'account_type'])
            ->assertSessionMissing('_old_input.account_number');
        $this->assertSame('not_submitted', $customer->fresh()->bankVerificationStatus());

        $this->get(route('profile.bank'))
            ->assertOk()
            ->assertSee('value="Morgan Example"', false)
            ->assertSee('id="bank-details-edit-form"', false)
            ->assertDontSee('hidden aria-hidden="true"', false);
    }

    public function test_edit_form_stays_open_after_validation_error_for_existing_account(): void
    {
        $customer = $this->customerWithBank('verified');
        $this->actingAs($customer, 'web')
            ->from(route('profile.bank'))
            ->put(route('profile.update'), [
                ...$this->bankDetails(),
                'account_holder_name' => 'Retained Customer Name',
                'ifsc_code' => 'invalid',
            ])
            ->assertSessionHasErrors('ifsc_code')
            ->assertSessionMissing('_old_input.account_number');

        $this->get(route('profile.bank'))
            ->assertOk()
            ->assertSee('aria-expanded="true"', false)
            ->assertSee('value="Retained Customer Name"', false)
            ->assertSee('id="bank-details-edit-form"', false)
            ->assertDontSee('hidden aria-hidden="true"', false);
    }

    private function bankDetails(): array
    {
        return [
            'section' => 'bank',
            'account_holder_name' => 'Morgan Example',
            'bank_name' => 'Example Bank',
            'account_number' => '123456789012',
            'ifsc_code' => 'ABCD0123456',
            'branch_name' => 'Central Branch',
            'account_type' => 'Savings',
        ];
    }

    private function customerWithBank(string $status, array $extra = []): User
    {
        $user = User::factory()->create([
            'account_holder_name' => 'Morgan Example',
            'bank_name' => 'Example Bank',
            'account_number' => '123456789012',
            'ifsc_code' => 'ABCD0123456',
            'branch_name' => 'Central Branch',
            'account_type' => 'Savings',
        ]);

        $user->forceFill([
            'bank_verification_status' => $status,
            'bank_submitted_at' => now(),
            'inr_balance' => $extra['inr_balance'] ?? '0.00',
            'wallet_transaction_password_hash' => Hash::make('1234'),
        ])->save();

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'totp_enabled' => true,
        ]);
    }

    private function withdrawalPayload(): array
    {
        return [
            'submission_key' => (string) Str::uuid(),
            'amount' => '100.00',
            'payout_method' => 'bank',
            'wallet_transaction_pin' => '1234',
        ];
    }
}

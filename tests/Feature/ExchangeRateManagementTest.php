<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\ExchangeRequest;
use App\Models\User;
use Database\Seeders\ExchangeRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_plans_are_seeded_idempotently_and_cannot_be_deleted(): void
    {
        $base = ExchangeRate::where('plan_key', 'base')->firstOrFail();
        $base->update(['rate' => '101.25000000']);

        (new ExchangeRateSeeder)->run();
        (new ExchangeRateSeeder)->run();

        $this->assertSame(3, ExchangeRate::count());
        $this->assertSame(101.25, (float) $base->fresh()->rate);
        $this->assertDatabaseHas('exchange_rates', [
            'plan_key' => 'base',
            'minimum_amount' => '0.00',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('exchange_rates', [
            'plan_key' => 'prime',
            'rate' => '115.00000000',
            'minimum_amount' => '10000.00',
        ]);
        $this->assertDatabaseHas('exchange_rates', [
            'plan_key' => 'vip',
            'rate' => '120.00000000',
            'minimum_amount' => '20000.00',
            'maximum_amount' => null,
        ]);

        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->delete(route('admin.rates.plans.destroy', $base))
            ->assertUnprocessable();

        $this->assertSame(3, ExchangeRate::count());
    }

    public function test_admin_can_create_edit_and_delete_custom_plans_and_public_pages_use_live_data(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);

        $this->get(route('admin.rates.edit'))
            ->assertOk()
            ->assertSee('Base Rate')
            ->assertSee('Prime Rate')
            ->assertSee('VIP Rate')
            ->assertSee('Add New Rate Plan')
            ->assertSee('Rate plans')
            ->assertSee('From (USDT)')
            ->assertSee('Upto (USDT)');

        $vip = ExchangeRate::where('plan_key', 'vip')->firstOrFail();
        $this->put(route('admin.rates.plans.update', $vip), [
            'name' => $vip->name,
            'rate' => (string) $vip->rate,
            'minimum_amount' => (string) $vip->minimum_amount,
            'maximum_amount' => '49999.99',
            'label' => $vip->label,
            'description' => $vip->description,
            'icon' => $vip->icon,
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('admin.rates.store'), [
            'name' => 'Institutional Rate',
            'rate' => '127.50000000',
            'minimum_amount' => '50000.00',
            'maximum_amount' => '59999.99',
            'label' => 'Institutional tier',
            'description' => 'A custom reference tier.',
            'icon' => 'bi-stars',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHas('status', 'Exchange rate plan created.');

        $plan = ExchangeRate::where('name', 'Institutional Rate')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.exchange_rate_plan_created']);

        $this->get(route('exchange'))
            ->assertOk()
            ->assertSee('Institutional Rate')
            ->assertSee('Institutional tier')
            ->assertSee('1 USDT = 127.5 INR')
            ->assertSee('₹127.5')
            ->assertSee('bi-stars');

        $this->put(route('admin.rates.plans.update', $plan), [
            'name' => 'Institutional Rate',
            'rate' => '129.75000000',
            'minimum_amount' => '60000.00',
            'maximum_amount' => null,
            'label' => 'Updated institutional tier',
            'description' => 'Updated custom rate description.',
            'icon' => 'bi-award',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHas('status', 'Exchange rate plan updated.');

        $plan->refresh();
        $this->assertSame(129.75, (float) $plan->rate);
        $this->assertSame(60000.0, (float) $plan->minimum_amount);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.exchange_rate_plan_updated']);
        $this->actingAs(User::factory()->create(), 'web')->get(route('home'))->assertOk()->assertSee('>100</span>', false);
        $this->get(route('exchange'))->assertOk()->assertSee('129.75')->assertSee('Updated institutional tier');

        $historicalRequest = ExchangeRequest::create([
            'user_id' => User::factory()->create()->id,
            'usdt_amount' => '10.00000000',
            'exchange_rate' => '129.75000000',
            'inr_amount' => '1297.50',
            'status' => 'pending',
        ]);
        $this->delete(route('admin.rates.plans.destroy', $plan))
            ->assertRedirect()
            ->assertSessionHas('status', 'Exchange rate plan deleted.');
        $this->assertDatabaseMissing('exchange_rates', ['id' => $plan->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.exchange_rate_plan_deleted']);
        $this->assertSame(129.75, (float) $historicalRequest->fresh()->exchange_rate);
        $this->get(route('exchange'))->assertDontSee('Institutional Rate');
    }

    public function test_disabled_plans_are_hidden_and_conflicting_minimum_thresholds_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);
        $prime = ExchangeRate::where('plan_key', 'prime')->firstOrFail();

        $this->put(route('admin.rates.plans.update', $prime), [
            'name' => $prime->name,
            'rate' => (string) $prime->rate,
            'minimum_amount' => (string) $prime->minimum_amount,
            'maximum_amount' => (string) $prime->maximum_amount,
            'label' => $prime->label,
            'description' => $prime->description,
            'icon' => $prime->icon,
            'is_active' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($prime->fresh()->is_active);

        $this->get(route('exchange'))->assertOk()->assertDontSee('Prime Rate')->assertSee('VIP Rate');

        $this->from(route('admin.rates.edit'))->post(route('admin.rates.store'), [
            'name' => 'Conflicting plan',
            'rate' => '125',
            'minimum_amount' => '20000.00',
            'maximum_amount' => '25000.00',
            'label' => '',
            'description' => '',
            'icon' => 'bi-star',
            'is_active' => '1',
        ])->assertSessionHasErrors('minimum_amount');

        $this->assertDatabaseMissing('exchange_rates', ['name' => 'Conflicting plan']);
    }

    public function test_default_plans_can_be_edited_but_base_threshold_must_remain_zero(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);
        $base = ExchangeRate::where('plan_key', 'base')->firstOrFail();

        $this->put(route('admin.rates.plans.update', $base), [
            'name' => 'Base Reference Rate',
            'rate' => '102.25000000',
            'minimum_amount' => '0',
            'maximum_amount' => '9999.99',
            'label' => 'MARKET REFERENCE',
            'description' => 'Updated base reference description.',
            'icon' => 'bi-bank',
            'is_active' => '0',
        ])->assertRedirect()->assertSessionHas('status', 'Exchange rate plan updated.');

        $base->refresh();
        $this->assertSame('Base Reference Rate', $base->name);
        $this->assertFalse($base->is_active);
        $this->assertSame(0.0, (float) $base->minimum_amount);
        $this->actingAs(User::factory()->create(), 'web')->get(route('home'))->assertDontSee('102.25')->assertDontSee('MARKET REFERENCE');
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'admin.exchange_rate_plan_updated',
        ]);

        $this->from(route('admin.rates.edit'))
            ->put(route('admin.rates.plans.update', $base), [
                'name' => 'Base Reference Rate',
                'rate' => '102.25',
                'minimum_amount' => '1',
                'maximum_amount' => '9999.99',
                'label' => 'MARKET REFERENCE',
                'description' => '',
                'icon' => 'bi-bank',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('minimum_amount');
        $this->assertSame(0.0, (float) $base->fresh()->minimum_amount);
    }

    public function test_status_changes_are_audited_and_edit_form_uses_validated_icon_options(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);
        $plan = ExchangeRate::where('plan_key', 'vip')->firstOrFail();

        $this->patch(route('admin.rates.plans.status', $plan))
            ->assertRedirect()
            ->assertSessionHas('status', 'Rate plan status updated.');

        $this->assertFalse($plan->fresh()->is_active);
        $audit = AuditLog::where('event', 'admin.exchange_rate_plan_updated')->latest()->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertTrue($audit->metadata['before']['is_active']);
        $this->assertFalse($audit->metadata['after']['is_active']);

        $this->from(route('admin.rates.edit'))
            ->post(route('admin.rates.store'), [
                'name' => 'Invalid Icon Plan',
                'rate' => '130',
                'minimum_amount' => '70000',
                'label' => '',
                'description' => '',
                'icon' => 'bi-anything-injected',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('icon');
        $this->assertDatabaseMissing('exchange_rates', ['name' => 'Invalid Icon Plan']);
    }

    public function test_rates_are_trimmed_for_display_without_changing_precision_or_request_snapshots(): void
    {
        $base = ExchangeRate::where('plan_key', 'base')->firstOrFail();
        $base->update(['rate' => '100.50000000']);
        $request = ExchangeRequest::create([
            'user_id' => User::factory()->create()->id,
            'usdt_amount' => '10.00000000',
            'exchange_rate' => '100.00000000',
            'inr_amount' => '1000.00',
            'status' => 'pending',
        ]);

        $this->actingAs(User::factory()->create(), 'web')->get(route('home'))->assertOk()->assertSee('>100.5</span>', false);
        $this->get(route('exchange'))->assertOk()->assertSee('1 USDT = 100.5 INR')->assertSee('₹100.5');

        $this->assertSame(100.5, (float) $base->fresh()->rate);
        $this->assertSame(100.0, (float) $request->fresh()->exchange_rate);
        $this->assertSame('100', ExchangeRate::formatDecimal('100.00000000'));
        $this->assertSame('100.5', ExchangeRate::formatDecimal('100.50000000'));
    }

    public function test_rate_plan_management_requires_admin_authentication(): void
    {
        $plan = ExchangeRate::where('plan_key', 'vip')->firstOrFail();

        $this->get(route('admin.rates.edit'))->assertRedirect(route('admin.login'));
        $this->delete(route('admin.rates.plans.destroy', $plan))->assertRedirect(route('admin.login'));
        $this->post(route('admin.rates.store'), [])->assertRedirect(route('admin.login'));
        $this->put(route('admin.rates.plans.update', $plan), [])->assertRedirect(route('admin.login'));
        $this->patch(route('admin.rates.plans.status', $plan))->assertRedirect(route('admin.login'));
    }

    public function test_active_rate_slab_ranges_cannot_overlap_or_be_activated_when_conflicting(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);

        $this->from(route('admin.rates.edit'))->post(route('admin.rates.store'), [
            'name' => 'Overlapping Active Plan',
            'rate' => '110',
            'minimum_amount' => '5000',
            'maximum_amount' => '15000',
            'label' => '',
            'description' => '',
            'icon' => 'bi-star',
            'is_active' => '1',
        ])->assertSessionHasErrors('maximum_amount');
        $this->assertDatabaseMissing('exchange_rates', ['name' => 'Overlapping Active Plan']);

        $this->post(route('admin.rates.store'), [
            'name' => 'Inactive Overlapping Plan',
            'rate' => '110',
            'minimum_amount' => '5000',
            'maximum_amount' => '15000',
            'label' => '',
            'description' => '',
            'icon' => 'bi-star',
            'is_active' => '0',
        ])->assertRedirect()->assertSessionHas('status', 'Exchange rate plan created.');

        $inactivePlan = ExchangeRate::where('name', 'Inactive Overlapping Plan')->firstOrFail();
        $this->from(route('admin.rates.edit'))
            ->patch(route('admin.rates.plans.status', $inactivePlan))
            ->assertSessionHasErrors('maximum_amount');
        $this->assertFalse($inactivePlan->fresh()->is_active);

        $this->from(route('admin.rates.edit'))->post(route('admin.rates.store'), [
            'name' => 'Invalid Range Plan',
            'rate' => '110',
            'minimum_amount' => '15000',
            'maximum_amount' => '10000',
            'label' => '',
            'description' => '',
            'icon' => 'bi-star',
            'is_active' => '0',
        ])->assertSessionHasErrors('maximum_amount');
    }

    public function test_withdrawal_quote_matches_inclusive_boundaries_and_uses_exact_decimal_math(): void
    {
        $this->get(route('exchange'))
            ->assertOk()
            ->assertSee('USDT to INR estimate')
            ->assertSee('Enter USDT Amount')
            ->assertSee('subject to verification and applicable fees')
            ->assertSee('0 – 9,999.99999999 USDT')
            ->assertSee('10,000 – 19,999.99999999 USDT')
            ->assertSee('20,000+ USDT')
            ->assertSee('aria-disabled="true"', false);

        $this->getJson(route('exchange.sell.estimate', ['amount' => '9999.99']))
            ->assertOk()
            ->assertJsonPath('plan', 'Base Rate')
            ->assertJsonPath('rate', '100')
            ->assertJsonPath('estimated_inr', '999999.00');

        $this->getJson(route('exchange.sell.estimate', ['amount' => '9999.99999999']))
            ->assertOk()
            ->assertJsonPath('plan', 'Base Rate')
            ->assertJsonPath('estimated_inr', '1000000.00');

        $this->getJson(route('exchange.sell.estimate', ['amount' => '10000']))
            ->assertOk()
            ->assertJsonPath('plan', 'Prime Rate')
            ->assertJsonPath('estimated_inr', '1150000.00');

        $this->getJson(route('exchange.sell.estimate', ['amount' => '19999.99']))
            ->assertOk()
            ->assertJsonPath('plan', 'Prime Rate')
            ->assertJsonPath('estimated_inr', '2299998.85');

        $this->getJson(route('exchange.sell.estimate', ['amount' => '19999.99999999']))
            ->assertOk()
            ->assertJsonPath('plan', 'Prime Rate')
            ->assertJsonPath('estimated_inr', '2300000.00');

        $this->getJson(route('exchange.sell.estimate', ['amount' => '20000']))
            ->assertOk()
            ->assertJsonPath('plan', 'VIP Rate')
            ->assertJsonPath('estimated_inr', '2400000.00');

        $this->getJson(route('exchange.sell.estimate', ['amount' => '15000']))
            ->assertOk()
            ->assertJsonPath('estimated_inr', '1725000.00');

        $this->getJson(route('exchange.sell.estimate', ['amount' => '500']))
            ->assertOk()
            ->assertJsonPath('estimated_inr', '50000.00');
        $this->getJson(route('exchange.sell.estimate', ['amount' => '25000']))
            ->assertOk()
            ->assertJsonPath('plan', 'VIP Rate')
            ->assertJsonPath('estimated_inr', '3000000.00');

        $this->getJson(route('exchange.sell.estimate', ['amount' => '0']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->getJson(route('exchange.sell.estimate', ['amount' => '0.00000001']))
            ->assertOk()
            ->assertJsonPath('estimated_inr', '0.00');

        ExchangeRate::where('plan_key', 'base')->update(['rate' => '100.12345678']);
        $this->getJson(route('exchange.sell.estimate', ['amount' => '1.23456789']))
            ->assertOk()
            ->assertJsonPath('estimated_inr', '123.61');

        ExchangeRate::where('plan_key', 'prime')->update(['is_active' => false]);
        $this->getJson(route('exchange.sell.estimate', ['amount' => '15000']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_rate_plan_search_filters_and_pagination_preserve_query_state(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);

        for ($index = 1; $index <= 18; $index++) {
            ExchangeRate::create([
                'pair' => 'USDT_INR',
                'plan_key' => null,
                'name' => sprintf('Searchable Plan %02d', $index),
                'rate' => '130.00000000',
                'minimum_amount' => number_format(30000 + ($index * 100), 2, '.', ''),
                'label' => $index === 3 ? 'Priority tier' : null,
                'description' => 'Extra reference plan',
                'icon' => 'bi-star',
                'is_active' => $index !== 4,
                'is_default' => false,
            ]);
        }

        $this->get(route('admin.rates.edit', ['type' => 'custom', 'status' => 'active']))
            ->assertOk()
            ->assertSee('Searchable Plan 01')
            ->assertDontSee('Searchable Plan 04')
            ->assertSee('page=2');

        $this->get(route('admin.rates.edit', ['search' => 'Priority tier']))
            ->assertOk()
            ->assertSee('Searchable Plan 03')
            ->assertDontSee('Searchable Plan 02');

        $this->get(route('admin.rates.edit', ['status' => 'inactive', 'type' => 'custom']))
            ->assertOk()
            ->assertSee('Searchable Plan 04')
            ->assertDontSee('Searchable Plan 01');

        $this->get(route('admin.rates.edit', ['search' => str_repeat('x', 121)]))
            ->assertSessionHasErrors('search');
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

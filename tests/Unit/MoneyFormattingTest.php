<?php

namespace Tests\Unit;

use App\Models\ExchangeRate;
use App\Support\AdminAuditPresenter;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyFormattingTest extends TestCase
{
    public function test_usdt_formatting_removes_only_insignificant_trailing_zeros(): void
    {
        $this->assertSame('500', Money::formatUsdt('500.00000000'));
        $this->assertSame('250.5', Money::formatUsdt('250.50000000'));
        $this->assertSame('125.25', Money::formatUsdt('125.25000000'));
        $this->assertSame('0.00000001', Money::formatUsdt('0.00000001'));
        $this->assertSame('1,234,567.12345678', Money::formatUsdt('1234567.12345678'));
    }

    public function test_currency_formatting_keeps_currency_precision_and_rate_significance(): void
    {
        $this->assertSame('$500.00', Money::formatUsd('500.00000000'));
        $this->assertSame('₹50,000.00', Money::formatInr('50000'));
        $this->assertSame('100.12345678', ExchangeRate::formatDecimal('100.12345678'));
        $this->assertSame('100.5', ExchangeRate::formatDecimal('100.50000000'));

        $ratePlan = new ExchangeRate([
            'minimum_amount' => '500.00000000',
            'maximum_amount' => '250.50000000',
        ]);
        $this->assertSame('500', $ratePlan->formattedMinimumAmount());
        $this->assertSame('250.5', $ratePlan->formattedMaximumAmount());
    }

    public function test_admin_audit_formats_usdt_without_cutting_significant_precision(): void
    {
        $presenter = new AdminAuditPresenter();

        $this->assertSame('500 USDT', $presenter->value('usdt_amount', '500.00000000', 'sell_request'));
        $this->assertSame('1.12345678 USDT', $presenter->value('usdt_amount', '1.12345678', 'sell_request'));
    }
}

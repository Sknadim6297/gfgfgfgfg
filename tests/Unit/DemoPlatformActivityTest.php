<?php

namespace Tests\Unit;

use App\Support\DemoPlatformActivity;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DemoPlatformActivityTest extends TestCase
{
    public function test_activity_is_identical_throughout_a_kolkata_calendar_day(): void
    {
        $early = CarbonImmutable::parse('2026-10-11 20:00:00', 'UTC');
        $late = CarbonImmutable::parse('2026-10-12 17:00:00', 'UTC');

        $this->assertSame(
            DemoPlatformActivity::forDate($early),
            DemoPlatformActivity::forDate($late),
        );
    }

    public function test_statistics_and_all_nine_conversion_rows_change_at_kolkata_midnight(): void
    {
        $beforeMidnight = CarbonImmutable::parse('2026-10-11 18:29:59', 'UTC');
        $afterMidnight = CarbonImmutable::parse('2026-10-11 18:30:00', 'UTC');
        $before = DemoPlatformActivity::forDate($beforeMidnight);
        $after = DemoPlatformActivity::forDate($afterMidnight);

        $this->assertNotSame($before['active_clients'], $after['active_clients']);
        $this->assertNotSame($before['deposit_usdt'], $after['deposit_usdt']);
        $this->assertNotSame($before['transactions'], $after['transactions']);
        $this->assertCount(9, $after['transactions']);
    }

    public function test_demo_values_are_consistent_and_contact_details_are_masked(): void
    {
        $activity = DemoPlatformActivity::forDate(CarbonImmutable::parse('2026-10-12', 'Asia/Kolkata'));

        $this->assertSame('$'.number_format($activity['deposit_usdt'] / 1000000, 1).'M', $activity['deposit_display']);
        $this->assertSame(number_format($activity['deposit_usdt'] * 110 / 10000000, 1).'Cr', $activity['inr_display']);
        foreach ($activity['transactions'] as $transaction) {
            $this->assertSame($transaction['deposit_usdt'] * 110, $transaction['inr']);
            $this->assertMatchesRegularExpression('/^\+91 \d{2}\*{4}\d{4}$/', $transaction['mobile']);
        }
    }
}

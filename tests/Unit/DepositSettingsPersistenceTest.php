<?php

namespace Tests\Unit;

use App\Support\DepositSettingsPersistence;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DepositSettingsPersistenceTest extends TestCase
{
    public function test_persisted_values_match_across_database_type_differences(): void
    {
        $this->assertTrue(DepositSettingsPersistence::matches('updated_by_user_id', '3', 3));
        $this->assertTrue(DepositSettingsPersistence::matches('is_enabled', 0, false));
        $this->assertTrue(DepositSettingsPersistence::matches('is_enabled', 1, true));
        $this->assertTrue(DepositSettingsPersistence::matches('minimum_amount', '5.25000000', '5.25000000'));
        $this->assertTrue(DepositSettingsPersistence::matches('minimum_amount', '5.25000000', '5.25'));
        $this->assertTrue(DepositSettingsPersistence::matches('token_contract_address', null, null));
        $this->assertFalse(DepositSettingsPersistence::matches('token_contract_address', '0x'.str_repeat('a', 40), '0x'.str_repeat('b', 40)));
        $this->assertFalse(DepositSettingsPersistence::matches('updated_by_user_id', 4, 3));
    }

    public function test_database_failures_keep_a_safe_admin_message(): void
    {
        $this->assertSame(
            'Deposit settings could not be saved because the database is missing required columns. Apply pending migrations, then try again.',
            DepositSettingsPersistence::adminMessage(new RuntimeException('SQLSTATE[42S22]: Unknown column token_contract_address'))
        );
        $this->assertSame(
            'Deposit settings could not be saved because the table does not support row locking. Convert deposit_settings to InnoDB, then try again.',
            DepositSettingsPersistence::adminMessage(new RuntimeException("SQLSTATE[HY000]: General error: 1031 Table storage engine doesn't have this option"))
        );
        $this->assertSame(
            'Deposit settings could not be saved because the updating administrator could not be confirmed. Please try again.',
            DepositSettingsPersistence::adminMessage(new RuntimeException('The saved deposit settings could not be verified for updated_by_user_id.'))
        );
        $this->assertSame(
            'Deposit settings could not be saved because the settings record could not be reloaded. Please try again.',
            DepositSettingsPersistence::adminMessage(new ModelNotFoundException('No query results for model [App\Models\DepositSettings] 0'))
        );
        $this->assertSame(
            'The payment QR image could not be stored.',
            DepositSettingsPersistence::adminMessage(new RuntimeException('The payment QR image could not be stored.'))
        );

        $leaked = "SQLSTATE[23000]: Integrity constraint violation: 1452 INSERT INTO deposit_settings (token_contract_address) VALUES ('0x".str_repeat('a', 40)."')";
        $safe = DepositSettingsPersistence::adminMessage(new RuntimeException($leaked));
        $this->assertSame('Deposit settings could not be saved because the database rejected the change. Please try again.', $safe);
        $this->assertStringNotContainsString('INSERT INTO', $safe);
        $this->assertStringNotContainsString('0x'.str_repeat('a', 40), $safe);

        $pathLeak = 'Unable to write file at C:\\inetpub\\vhosts\\example\\storage\\app\\private\\deposits\\qr\\abc.png';
        $pathMessage = DepositSettingsPersistence::adminMessage(new RuntimeException($pathLeak));
        $this->assertSame('Deposit settings could not be saved. Please try again.', $pathMessage);
        $this->assertStringNotContainsString('inetpub', $pathMessage);
        $this->assertStringNotContainsString('storage', $pathMessage);
    }
}

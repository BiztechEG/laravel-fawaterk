<?php

namespace BiztechEG\Fawaterk\Tests\MySql;

use BiztechEG\Fawaterk\Ledger\Ledger;
use Illuminate\Support\Facades\Artisan;

class DoctorTest extends MySqlTestCase
{
    public function test_doctor_reads_the_key_type_from_the_server(): void
    {
        Artisan::call('fawaterk:doctor', ['--offline' => true]);
        $this->assertStringContainsString('payable_id matches payable_key_type (int)', Artisan::output());

        config()->set('fawaterk.ledger.payable_key_type', 'uuid');
        Artisan::call('fawaterk:doctor', ['--offline' => true]);
        $this->assertStringContainsString('payable_id is bigint, but fawaterk.ledger.payable_key_type is uuid', Artisan::output());

        $this->assertSame(Ledger::table('payments'), 'fawaterk_payments');
    }
}

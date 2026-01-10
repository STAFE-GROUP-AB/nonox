<?php

namespace Tests\Unit;

use App\Models\Voucher;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_voucher_validates_double_entry_correctly(): void
    {
        $company = Company::create([
            'org_number' => '556677-8899',
            'name' => 'Test AB',
            'fiscal_year_start' => '2026-01-01',
            'fiscal_year_end' => '2026-12-31',
        ]);

        // Valid double entry: debit = credit
        $validVoucher = Voucher::create([
            'company_id' => $company->id,
            'voucher_number' => 'V001',
            'date' => '2026-01-15',
            'description' => 'Test verifikation',
            'lines' => [
                ['account' => '1510', 'debit' => 1000.00, 'credit' => 0],
                ['account' => '3000', 'debit' => 0, 'credit' => 1000.00],
            ],
        ]);

        $this->assertTrue($validVoucher->validateDoubleEntry());

        // Invalid double entry: debit != credit
        $invalidVoucher = Voucher::create([
            'company_id' => $company->id,
            'voucher_number' => 'V002',
            'date' => '2026-01-15',
            'description' => 'Invalid verifikation',
            'lines' => [
                ['account' => '1510', 'debit' => 1000.00, 'credit' => 0],
                ['account' => '3000', 'debit' => 0, 'credit' => 500.00],
            ],
        ]);

        $this->assertFalse($invalidVoucher->validateDoubleEntry());
    }
}

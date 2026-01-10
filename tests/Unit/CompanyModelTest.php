<?php

namespace Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_can_be_created(): void
    {
        $company = Company::create([
            'org_number' => '556677-8899',
            'name' => 'Test AB',
            'address' => 'Testgatan 1, 123 45 Stockholm',
            'vat_number' => 'SE556677889901',
            'fiscal_year_start' => '2026-01-01',
            'fiscal_year_end' => '2026-12-31',
            'base_currency' => 'SEK',
            'bankgiro' => '123-4567',
        ]);

        $this->assertInstanceOf(Company::class, $company);
        $this->assertEquals('556677-8899', $company->org_number);
        $this->assertEquals('Test AB', $company->name);
    }

    public function test_company_fiscal_year_dates_are_cast_to_dates(): void
    {
        $company = Company::create([
            'org_number' => '556677-8899',
            'name' => 'Test AB',
            'fiscal_year_start' => '2026-01-01',
            'fiscal_year_end' => '2026-12-31',
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $company->fiscal_year_start);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $company->fiscal_year_end);
    }
}

<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\PayCodeCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The tax-free mileage pay codes for a company car where the employee paid
 * all the fuel (IL 12 kap. 5 § andra stycket, ADR-072): MIL_FORMANSBIL and
 * MIL_FORMANSBIL_EL. Companies that turned payroll on before them get them
 * here, linked to their account 7331 when it exists; SeedPayCodes adds them
 * for companies turning payroll on later. A company that already has a pay
 * code with the same code keeps its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = DB::table('pay_codes')->where('code', 'MILERSATTNING')->pluck('company_id')->unique();

        foreach ($companies as $companyId) {
            foreach (['MIL_FORMANSBIL', 'MIL_FORMANSBIL_EL'] as $code) {
                if (DB::table('pay_codes')->where('company_id', $companyId)->where('code', $code)->exists()) {
                    continue;
                }

                $definition = PayCodeCatalog::CODES[$code];

                DB::table('pay_codes')->insert([
                    'company_id' => $companyId,
                    'code' => $code,
                    'name_sv' => $definition['name_sv'],
                    'name_en' => $definition['name_en'],
                    'category' => $definition['category'],
                    'unit' => $definition['unit'],
                    'is_taxable' => $definition['taxable'],
                    'tax_method' => $definition['tax_method'],
                    'is_contribution_basis' => $definition['contribution'],
                    'is_vacation_basis' => $definition['vacation'],
                    'is_pension_basis' => $definition['pension'],
                    'debit_account_id' => DB::table('accounts')->where('company_id', $companyId)->where('number', $definition['debit'])->value('id'),
                    'credit_account_id' => null,
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Account;
use App\Models\Company;
use App\Modules\Payroll\Domain\PayCodeCatalog;
use App\Modules\Payroll\Models\PayCode;
use App\Support\Tenancy\CurrentCompany;

/**
 * Adds the system pay codes a company does not have yet, linked to the
 * company's accounts where they exist. Never changes an existing pay code,
 * so re-enabling payroll keeps the company's own mapping.
 */
class SeedPayCodes
{
    public function __invoke(Company $company): int
    {
        return CurrentCompany::run($company, function (): int {
            $existing = PayCode::query()->pluck('code')->all();
            $accounts = Account::query()->whereIn('number', array_keys(PayCodeCatalog::ACCOUNTS))->pluck('id', 'number');
            $created = 0;

            foreach (PayCodeCatalog::CODES as $code => $definition) {
                if (in_array($code, $existing, true)) {
                    continue;
                }

                PayCode::create([
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
                    'debit_account_id' => $definition['debit'] === null ? null : ($accounts[$definition['debit']] ?? null),
                    'credit_account_id' => $definition['credit'] === null ? null : ($accounts[$definition['credit']] ?? null),
                    'is_system' => true,
                ]);
                $created++;
            }

            return $created;
        });
    }
}

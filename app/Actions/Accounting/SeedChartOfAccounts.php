<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\AccountType;
use App\Domain\Company\ReportingFramework;
use App\Models\Account;
use App\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use RuntimeException;

/**
 * Seeds the BAS template matching the company's reporting framework
 * (AGENTS.md §6.1). Accounts that already exist are left untouched, so it is
 * safe to run again.
 */
class SeedChartOfAccounts
{
    public function __invoke(Company $company): int
    {
        $template = $this->template($company->reporting_framework);

        return CurrentCompany::run($company, function () use ($template): int {
            $existing = Account::query()->pluck('number')->all();
            $created = 0;

            foreach ($template as $account) {
                if (in_array($account['number'], $existing, true)) {
                    continue;
                }

                Account::create([
                    'number' => $account['number'],
                    'name' => $account['name'],
                    'type' => AccountType::forNumber($account['number']),
                ]);

                $created++;
            }

            return $created;
        });
    }

    /** @return list<array{number: int, name: string}> */
    public function template(ReportingFramework $framework): array
    {
        $path = database_path('data/bas/2026/'.strtolower($framework->value).'.json');
        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['accounts'] ?? null)) {
            throw new RuntimeException("The BAS template {$path} could not be read.");
        }

        return array_values(array_map(fn (array $account): array => [
            'number' => (int) $account['number'],
            'name' => (string) $account['name'],
        ], $data['accounts']));
    }
}

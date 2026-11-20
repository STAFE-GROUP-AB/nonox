<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Actions\Company\CreateCompany;
use App\Domain\Accounting\AccountType;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\Account;
use App\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use RuntimeException;

/**
 * Seeds the BAS template matching the company's reporting framework
 * (AGENTS.md §6.1). Accounts that already exist are left untouched, so it is
 * safe to run again. The equity block follows the legal form: a sole trader
 * or partnership gets the owner equity accounts (2010–2019) whatever its
 * framework, never a limited company's 2081 Aktiekapital.
 * TODO(legal-verify): own equity blocks for ekonomisk förening, ideell
 * förening and stiftelse (they keep the framework's block for now).
 */
class SeedChartOfAccounts
{
    public function __invoke(Company $company): int
    {
        $template = $this->template($company->reporting_framework, $company->legal_form, UpgradeBasChart::year((string) ($company->bas_version ?: CreateCompany::BAS_VERSION)));

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
    public function template(ReportingFramework $framework, ?LegalForm $legalForm = null, ?string $year = null): array
    {
        $year ??= UpgradeBasChart::year(CreateCompany::BAS_VERSION);

        $accounts = $this->file($framework, $year);

        if (! in_array($legalForm, [LegalForm::Ef, LegalForm::Hb, LegalForm::Kb], true) || $framework === ReportingFramework::K1) {
            return $accounts;
        }

        $ownerEquity = array_values(array_filter($this->file(ReportingFramework::K1, $year), fn (array $account): bool => $account['number'] >= 2000 && $account['number'] < 2100));
        $rest = array_values(array_filter($accounts, fn (array $account): bool => $account['number'] < 2000 || $account['number'] >= 2100));

        $merged = [...$rest, ...$ownerEquity];
        usort($merged, fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return $merged;
    }

    /** @return list<array{number: int, name: string}> */
    private function file(ReportingFramework $framework, string $year): array
    {
        $path = UpgradeBasChart::folder($year).'/'.strtolower($framework->value).'.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($data) || ! is_array($data['accounts'] ?? null)) {
            throw new RuntimeException("The BAS template {$path} could not be read.");
        }

        return array_values(array_map(fn (array $account): array => [
            'number' => (int) $account['number'],
            'name' => (string) $account['name'],
        ], $data['accounts']));
    }
}

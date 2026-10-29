<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\LedgerVerifier;
use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;

/**
 * Recomputes the hash chain, balances and numbering of the ledger
 * (AGENTS.md §6.4). Runs nightly for every company.
 */
class VerifyLedgerCommand extends Command
{
    protected $signature = 'ledger:verify {company? : Företagets id eller organisationsnummer} {--all : Kontrollera alla företag}';

    protected $description = 'Kontrollerar att bokföringen inte har ändrats: hashkedjan, balansen och numreringen';

    public function handle(LedgerVerifier $verifier): int
    {
        $companies = $this->companies();

        if ($companies === []) {
            $this->error('Ange ett företag (id eller organisationsnummer) eller --all.');

            return self::INVALID;
        }

        $failed = 0;

        foreach ($companies as $company) {
            $this->line("Kontrollerar {$company->name} ({$company->org_number})...");

            $result = $verifier->verify($company);

            Audit::record('ledger.verified', $company, [
                'ok' => $result['ok'],
                'vouchers' => $result['vouchers'],
                'problems' => $result['problems'],
            ], $company);

            if ($result['ok']) {
                $this->info("  OK: {$result['vouchers']} bokförda verifikationer, inga avvikelser.");

                continue;
            }

            $failed++;

            foreach ($result['problems'] as $problem) {
                $this->error("  {$problem}");
            }
        }

        $this->comment($failed === 0
            ? 'Bokföringen är intakt.'
            : "Avvikelser hittades i {$failed} företag.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<Company> */
    private function companies(): array
    {
        if ($this->option('all')) {
            return array_values(Company::query()->orderBy('id')->get()->all());
        }

        $identifier = $this->argument('company');

        if (! is_string($identifier) || $identifier === '') {
            return [];
        }

        $orgNumber = OrganisationNumber::tryFrom($identifier);

        $company = $orgNumber !== null
            ? Company::query()->where('org_number', $orgNumber->formatted())->first()
            : (ctype_digit($identifier) ? Company::query()->find((int) $identifier) : null);

        return $company instanceof Company ? [$company] : [];
    }
}

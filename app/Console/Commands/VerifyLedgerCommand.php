<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\LedgerVerifier;
use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes the hash chain, balances and numbering of the ledger
 * (AGENTS.md §6.4). Runs nightly for every company.
 */
class VerifyLedgerCommand extends Command
{
    /** The check ran and found deviations. Any other non-zero code means it crashed (ADR-078). */
    public const int EXIT_DEVIATIONS = 3;

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
        $failedCompanies = [];

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
            $failedCompanies[] = $company->id;

            foreach ($result['problems'] as $problem) {
                $this->error("  {$problem}");
            }
        }

        $this->comment($failed === 0
            ? 'Bokföringen är intakt.'
            : "Avvikelser hittades i {$failed} företag.");

        if ($failed > 0) {
            // The operator's alert: the log channel, company ids only. The public status page shows just that the check ran (ADR-078).
            Log::error('ledger:verify hittade avvikelser i bokföringen', ['companies' => $failedCompanies]);
        }

        return $failed === 0 ? self::SUCCESS : self::EXIT_DEVIATIONS;
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

        if ($orgNumber !== null) {
            // A hosted installation may hold the same number in several accounts.
            return array_values(Company::query()->where('org_number', $orgNumber->formatted())->orderBy('id')->get()->all());
        }

        $company = ctype_digit($identifier) ? Company::query()->find((int) $identifier) : null;

        return $company instanceof Company ? [$company] : [];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Console;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Modules\StaffLedger\Domain\ChainVerifier;
use App\Modules\StaffLedger\Models\Entry;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Recomputes the staff ledger's hash chains (ADR-022). Without an argument
 * every company with a staff ledger is checked; it runs nightly. The result
 * is written to the audit log and shown on the overview.
 */
class VerifyStaffLedgerCommand extends Command
{
    protected $signature = 'personalliggare:verify {company? : Företagets id eller organisationsnummer}';

    protected $description = 'Kontrollerar att personalliggaren inte har ändrats: hashkedjan per lokal';

    public function handle(ChainVerifier $verifier): int
    {
        $companies = $this->companies();

        if ($companies === null) {
            $this->error('Företaget finns inte.');

            return self::INVALID;
        }

        $failed = 0;

        foreach ($companies as $company) {
            $this->line("Kontrollerar personalliggaren för {$company->name} ({$company->org_number})...");
            $result = $verifier->verify($company);

            Audit::record('staff_ledger.verified', $company, $result, $company);

            if ($result['ok']) {
                $this->info("  OK: {$result['entries']} poster, inga avvikelser.");

                continue;
            }

            $failed++;

            foreach ($result['problems'] as $problem) {
                $this->error("  {$problem}");
            }
        }

        $this->comment($failed === 0 ? 'Personalliggaren är intakt.' : "Avvikelser hittades i {$failed} företag.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<Company>|null */
    private function companies(): ?array
    {
        $identifier = $this->argument('company');

        if (! is_string($identifier) || $identifier === '') {
            // Every company that has written to a staff ledger, whether the module is on or not.
            $ids = CompanyScope::bypass(fn (): array => Entry::query()->distinct()->pluck('company_id')->all());

            return array_values(Company::query()->whereIn('id', $ids)->orderBy('id')->get()->all());
        }

        $orgNumber = OrganisationNumber::tryFrom($identifier);

        if ($orgNumber !== null) {
            $found = array_values(Company::query()->where('org_number', $orgNumber->formatted())->orderBy('id')->get()->all());

            return $found === [] ? null : $found;
        }

        $company = ctype_digit($identifier) ? Company::query()->find((int) $identifier) : null;

        return $company instanceof Company ? [$company] : null;
    }
}

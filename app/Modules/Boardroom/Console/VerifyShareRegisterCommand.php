<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Console;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Modules\Boardroom\Domain\ChainVerifier;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Recomputes the share register's hash chain (ADR-070 §2, the pattern of
 * ledger:verify). Without an argument every company with a register is
 * checked; it runs nightly. The result is written to the audit log and shown
 * on the register page.
 */
class VerifyShareRegisterCommand extends Command
{
    protected $signature = 'share-register:verify {company? : Företagets id eller organisationsnummer}';

    protected $description = 'Kontrollerar att aktieboken inte har ändrats: hashkedjan och aktienumren';

    public function handle(ChainVerifier $verifier): int
    {
        $companies = $this->companies();

        if ($companies === null) {
            $this->error('Företaget finns inte.');

            return self::INVALID;
        }

        $failed = 0;

        foreach ($companies as $company) {
            $this->line("Kontrollerar aktieboken för {$company->name} ({$company->org_number})...");
            $result = $verifier->verify($company);

            Audit::record('share_register.verified', $company, $result, $company);

            if ($result['ok']) {
                $this->info("  OK: {$result['entries']} händelser, inga avvikelser.");

                continue;
            }

            $failed++;

            foreach ($result['problems'] as $problem) {
                $this->error("  {$problem}");
            }
        }

        $this->comment($failed === 0 ? 'Aktieboken är intakt.' : "Avvikelser hittades i {$failed} företag.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<Company>|null */
    private function companies(): ?array
    {
        $identifier = $this->argument('company');

        if (! is_string($identifier) || $identifier === '') {
            // Every company that has written to a share register, whether the module is on or not.
            $ids = CompanyScope::bypass(fn (): array => ShareEvent::query()->distinct()->pluck('company_id')->all());

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

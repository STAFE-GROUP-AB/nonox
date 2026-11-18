<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Company\OrganisationNumber;
use App\Domain\Signing\Chain\ChainVerifier;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Recomputes every signing envelope's hash chain and file hashes
 * (ADR-046 amendment, gap 4, package S2), the pattern of ledger:verify.
 * Without an argument every company with an envelope is checked, archived
 * companies included; it runs nightly. The result goes to the audit log as
 * signing.verified (counts, problem codes and ids) and is shown in each
 * signing panel.
 */
class VerifySigningChainsCommand extends Command
{
    public const string ACTION = 'signing.verified';

    protected $signature = 'signing:verify {company? : Företagets id eller organisationsnummer}';

    protected $description = 'Kontrollerar att signeringarnas händelsekedjor och dokument inte har ändrats';

    public function handle(ChainVerifier $verifier): int
    {
        $companies = $this->companies();

        if ($companies === null) {
            $this->error('Företaget finns inte.');

            return self::INVALID;
        }

        $failed = 0;

        foreach ($companies as $company) {
            $this->line("Kontrollerar signeringarna för företag {$company->id}...");
            $result = $verifier->verify($company);

            Audit::record(self::ACTION, $company, $result, $company);

            if ($result['ok']) {
                $this->info("  OK: {$result['envelopes']} kuvert, {$result['events']} händelser, {$result['files']} filer, inga avvikelser.");

                continue;
            }

            $failed++;

            foreach ($result['problems'] as $problem) {
                $this->error('  '.$problem['code'].' i kuvert '.($problem['envelope_id'] ?? '–').(isset($problem['sequence']) ? ", händelse {$problem['sequence']}" : ''));
            }
        }

        $this->comment($failed === 0 ? 'Kontrollerade '.count($companies).' företag. Signeringarna är intakta.' : "Avvikelser hittades i {$failed} företag.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<Company>|null */
    private function companies(): ?array
    {
        $identifier = $this->argument('company');

        if (! is_string($identifier) || $identifier === '') {
            // Only which companies have envelopes is read across companies; each is verified inside its own.
            $ids = CompanyScope::bypass(fn (): array => SigningEnvelope::query()->distinct()->pluck('company_id')->all());

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

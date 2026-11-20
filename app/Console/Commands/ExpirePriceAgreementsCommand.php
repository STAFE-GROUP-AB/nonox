<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\ExpirePriceAgreements;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Models\Company;
use App\Models\PriceAgreement;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Ends price agreements whose last day has passed and lets agreements
 * linked to the CRM agreement register follow its end date (ADR-082 §4,
 * P5), every morning, in each company's own context. Safe to run again.
 */
class ExpirePriceAgreementsCommand extends Command
{
    protected $signature = 'pricing:expire';

    protected $description = 'Ends price agreements whose last day has passed';

    public function handle(): int
    {
        $today = CarbonImmutable::today();

        // Which companies have agreements to look at is read across companies; each is then handled inside its own.
        // An archived company is read-only (ADR-024) and is left as it is.
        $companies = CompanyScope::bypass(fn (): array => PriceAgreement::query()
            ->whereIn('status', [PriceAgreementStatus::Draft->value, PriceAgreementStatus::Active->value])
            ->where(fn ($query) => $query->whereNotNull('crm_agreement_id')->orWhere('valid_to', '<', $today->toDateString()))
            ->whereIn('company_id', Company::query()->whereNull('archived_at')->select('id'))
            ->distinct()
            ->orderBy('company_id')
            ->pluck('company_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());

        $ended = 0;

        foreach ($companies as $companyId) {
            $this->info("Checking company `{$companyId}`...");
            $result = ExpirePriceAgreements::forCompany($companyId, $today);
            $ended += $result['ended'];
        }

        $this->comment("Ended {$ended} price agreements in ".count($companies).' companies.');

        return self::SUCCESS;
    }
}

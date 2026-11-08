<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Signing\PruneSigningLinks;
use App\Models\SigningRequest;
use App\Support\Tenancy\ArchivedCompany;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Deletes signing links that were never signed, a year after they closed
 * (PruneSigningLinks, ADR-046), in every company that has any, whether or
 * not CRM is on: quotes are signed at links without CRM too (ADR-075 §7).
 * Archived companies included: the data has no purpose left there either.
 */
class PruneSigningLinksCommand extends Command
{
    protected $signature = 'signing:prune';

    protected $description = 'Gallrar signeringslänkar som aldrig signerades, ett år efter att de stängdes.';

    public function handle(PruneSigningLinks $prune): int
    {
        $today = CarbonImmutable::now('Europe/Stockholm')->startOfDay();
        // Only which companies have links is read across companies; each is pruned inside its own.
        $companies = CompanyScope::bypass(fn (): array => SigningRequest::query()->distinct()->orderBy('company_id')->pluck('company_id')->map(fn (mixed $id): int => (int) $id)->all());
        $pruned = 0;

        foreach ($companies as $companyId) {
            $this->info("Pruning signing links in company `{$companyId}`...");
            $pruned += ArchivedCompany::maintenance(fn (): int => CurrentCompany::run($companyId, fn (): int => $prune($today)));
        }

        $this->comment('Checked '.count($companies)." companies, {$pruned} unsigned links pruned.");

        return self::SUCCESS;
    }
}

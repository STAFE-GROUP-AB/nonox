<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Signing\PruneSigningLinks;
use App\Support\Tenancy\ArchivedCompany;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes signing links that were never signed, a year after they closed,
 * whole envelopes whose retention date has passed (ADR-046 S16) and
 * standalone documents that were never sent (PruneSigningLinks), in every
 * company that has any, whether or not CRM is on: quotes are signed at links
 * without CRM too (ADR-075 §7). Archived companies included: the data has no
 * purpose left there either. An envelope on legal hold is never touched.
 */
class PruneSigningLinksCommand extends Command
{
    protected $signature = 'signing:prune';

    protected $description = 'Gallrar signeringslänkar som aldrig signerades, ett år efter att de stängdes, och signeringar vars bevarandetid har gått ut.';

    public function handle(PruneSigningLinks $prune): int
    {
        $today = CarbonImmutable::now('Europe/Stockholm')->startOfDay();
        // Only which companies have links, envelopes or documents is read across companies (plain query builder, no
        // company scope); each is pruned inside its own.
        $companies = DB::table('crm_signing_requests')->select('company_id')
            ->union(DB::table('signing_envelopes')->select('company_id'))
            ->union(DB::table('signing_documents')->select('company_id'))
            ->pluck('company_id')->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
        $totals = ['links' => 0, 'envelopes' => 0, 'documents' => 0];

        foreach ($companies as $companyId) {
            $this->info("Pruning signing links in company `{$companyId}`...");
            /** @var array{links: int, envelopes: int, documents: int} $pruned */
            $pruned = ArchivedCompany::maintenance(fn (): array => CurrentCompany::run($companyId, fn (): array => $prune->run($today)));

            foreach ($totals as $key => $count) {
                $totals[$key] = $count + $pruned[$key];
            }
        }

        $this->comment('Checked '.count($companies)." companies, {$totals['links']} unsigned links, {$totals['envelopes']} envelopes past their retention and {$totals['documents']} never-sent documents pruned.");

        return self::SUCCESS;
    }
}

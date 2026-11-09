<?php

declare(strict_types=1);

namespace App\Modules\Portal\Console;

use App\Models\Company;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\Support\PortalRetention;
use App\Support\Tenancy\ArchivedCompany;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The customer portal's retention (PortalRetention, ADR-075 §12), daily,
 * in every company that has portal data, whether or not the module is on
 * now: data kept while it was on still outlives its purpose. Touches the
 * portal's own tables only.
 */
class PrunePortalCommand extends Command
{
    protected $signature = 'portal:prune';

    protected $description = 'Gallrar kundportalens inbjudningar, inaktiva och avstängda kontakter, händelser och stängda frågor.';

    public function handle(PortalRetention $retention): int
    {
        $now = CarbonImmutable::now();
        // Only which companies have portal data is read across companies; each is pruned inside its own.
        $ids = CompanyScope::bypass(fn (): array => collect([
            ...PortalContact::query()->distinct()->pluck('company_id')->all(),
            ...PortalEvent::query()->distinct()->pluck('company_id')->all(),
            ...PortalThread::query()->distinct()->pluck('company_id')->all(),
        ])->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all());

        $totals = [];

        foreach ($ids as $id) {
            $company = Company::query()->find($id);

            if (! $company instanceof Company) {
                continue;
            }

            $this->info("Pruning the customer portal of company `{$id}`...");

            foreach (ArchivedCompany::maintenance(fn (): array => $retention->prune($company, $now)) as $key => $count) {
                $totals[$key] = ($totals[$key] ?? 0) + $count;
            }
        }

        $this->comment('Checked '.count($ids).' companies. '.collect($totals)->map(fn (int $count, string $key): string => "{$key}: {$count}")->implode(', ').'.');

        return self::SUCCESS;
    }
}

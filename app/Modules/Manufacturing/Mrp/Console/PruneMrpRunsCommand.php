<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp\Console;

use App\Models\Company;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Manufacturing\Mrp\Models\MrpRun;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Deletes planning runs older than 30 days with their planned orders
 * (ADR-081 gap 5), each company inside its own scope. The drafts made from
 * them are ordinary orders and stay.
 */
class PruneMrpRunsCommand extends Command
{
    protected $signature = 'mrp:prune';

    protected $description = 'Tar bort behovsplaneringar som är äldre än 30 dagar';

    public function handle(ModuleManager $modules): int
    {
        $deleted = 0;

        foreach ($modules->companiesWith(InventoryModule::KEY) as $id) {
            $company = Company::query()->find($id);

            if (! $company instanceof Company) {
                continue;
            }

            $this->info("Pruning company `{$id}`...");
            $deleted += CurrentCompany::run($company, fn (): int => (int) MrpRun::query()->where('created_at', '<', now()->subDays(MrpRun::KEEP_DAYS))->delete());
        }

        $this->comment("Deleted {$deleted} planning runs.");

        return self::SUCCESS;
    }
}

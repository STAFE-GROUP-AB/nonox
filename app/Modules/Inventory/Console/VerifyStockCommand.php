<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Models\Company;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Support\StockBalanceCheck;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Checks that every stock balance is the sum of its movements (ADR-039), for
 * one company or every company with Lager on. Reports differences and exits
 * with an error; it never changes anything.
 */
class VerifyStockCommand extends Command
{
    protected $signature = 'inventory:verify {company? : company id; every company with Lager on when omitted}';

    protected $description = 'Check that the stock balances match the movement ledger';

    public function handle(ModuleManager $modules): int
    {
        $ids = $this->argument('company') !== null
            ? [(int) $this->argument('company')]
            : $modules->companiesWith(InventoryModule::KEY);

        $failed = 0;

        foreach ($ids as $id) {
            // The command looks across companies; each one is checked in its own company.
            $company = CompanyScope::bypass(fn (): ?Company => Company::query()->find($id));

            if (! $company instanceof Company) {
                $this->error("Company {$id} does not exist.");
                $failed++;

                continue;
            }

            $this->info("Checking company `{$company->id}`...");
            $differences = StockBalanceCheck::differences($company);

            foreach ($differences as $difference) {
                $this->error("Article {$difference['article_id']} at location {$difference['location_id']}: balance {$difference['balance']}, movements {$difference['movements']}.");
            }

            $failed += $differences === [] ? 0 : 1;
        }

        if ($failed > 0) {
            $this->error("{$failed} companies have stock balances that do not match their movements.");

            return self::FAILURE;
        }

        $this->comment('Checked '.count($ids).' companies. All stock balances match their movements.');

        return self::SUCCESS;
    }
}

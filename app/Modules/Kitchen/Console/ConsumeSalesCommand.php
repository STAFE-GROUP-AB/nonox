<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Console;

use App\Models\Company;
use App\Modules\Kitchen\Actions\ConsumeSales;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\ModuleManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Draws the till's sales of dishes from stock through their recipes, for
 * every company with Kök on and drawing on sale switched on (ADR-073 §5).
 * Runs nightly after pos:reconcile-sales. Exactly once per till row.
 */
class ConsumeSalesCommand extends Command
{
    protected $signature = 'kitchen:consume {--company= : Only this company id}';

    protected $description = 'Draw the ingredients of the till\'s dish sales from stock through the recipes';

    public function handle(ModuleManager $modules, ConsumeSales $consume): int
    {
        $companies = $this->option('company') !== null ? [(int) $this->option('company')] : $modules->companiesWith(KitchenModule::KEY);
        $rows = 0;

        foreach ($companies as $companyId) {
            $company = Company::query()->find($companyId);

            if (! $company instanceof Company) {
                continue;
            }

            $this->info("Drawing sales for company `{$companyId}`...");

            // One company's broken data (a recipe, a location) never stops the others.
            try {
                $result = $consume($company);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("  failed: {$exception->getMessage()}");

                continue;
            }

            $this->line("  {$result['rows']} till rows, {$result['movements']} movements, {$result['waiting']} waiting for stock.");
            $rows += $result['rows'];
        }

        $this->comment("Drew {$rows} till rows from stock.");

        return self::SUCCESS;
    }
}

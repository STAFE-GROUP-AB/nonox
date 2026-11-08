<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Console;

use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Support\ExpireChangeOrders;
use App\Modules\WorkOrders\WorkOrdersModule;
use Illuminate\Console\Command;

/**
 * Marks sent ÄTA whose signing links have all run out as expired, in every
 * company with Arbetsorder on, each in its own company (ADR-074 review).
 */
class ExpireChangeOrdersCommand extends Command
{
    protected $signature = 'work-orders:expire-change-orders';

    protected $description = 'Mark sent ÄTA whose signing links have run out as expired';

    public function handle(ModuleManager $modules): int
    {
        $total = 0;

        foreach ($modules->companiesWith(WorkOrdersModule::KEY) as $companyId) {
            $this->info("Checking company `{$companyId}`...");
            $total += ExpireChangeOrders::forCompany($companyId);
        }

        $this->comment("Marked {$total} ÄTA as expired.");

        return self::SUCCESS;
    }
}

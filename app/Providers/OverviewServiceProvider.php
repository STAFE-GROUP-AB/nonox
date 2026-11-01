<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\OverviewCache;
use App\Domain\Overview\TodoProviders;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Invoice;
use App\Models\OpeningBalance;
use App\Models\SupplierInvoice;
use App\Models\Voucher;
use Illuminate\Support\ServiceProvider;

/**
 * Översikt (ADR-027): makes a company's cached overview stale when its data
 * changes. Registered after the modules, so their todo providers are known.
 */
final class OverviewServiceProvider extends ServiceProvider
{
    /** Models the key figures, the vouchers and the statistics read. */
    private const array MODELS = [
        Company::class,
        CompanyModule::class,
        Voucher::class,
        Invoice::class,
        SupplierInvoice::class,
        OpeningBalance::class,
        BankReconciliation::class,
    ];

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $models = self::MODELS;

            foreach ($this->app->make(TodoProviders::class)->installed() as $provider) {
                /** @var TodoProvider $provider */
                array_push($models, ...$provider->invalidatedBy());
            }

            OverviewCache::listen($models);
        });
    }
}

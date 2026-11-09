<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\Accounting\LockPeriod;
use App\Jobs\BuildArchivePackage;
use App\Models\ArchivePackage;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

/**
 * Runs last for the consultancy, when the other extras have booked what
 * they book: locks the bookkeeping up to the last declared VAT quarter,
 * orders an archive package of the year so far, and runs the ledger check
 * once, so its result is on the vouchers page.
 */
final class WrapUpExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        try {
            app(LockPeriod::class)($c->user, $c->company, CarbonImmutable::parse('2026-09-30'));
            $package = app(CreateArchivePackage::class)($c->user, $c->fiscalYear);

            // The package is built by a job. If the queue has not run it already, build it here and now, in this process
            // and on this database; the queued job finds it started and does nothing. The queue setting is never touched.
            if ($package->status === ArchivePackage::QUEUED) {
                BuildArchivePackage::dispatchSync($c->company->id, $package->id);
            }
        } catch (ValidationException $exception) {
            $c->warn(collect($exception->errors())->flatten()->implode(' '));
        }

        Artisan::call('ledger:verify', ['company' => (string) $c->company->id]);
    }
}

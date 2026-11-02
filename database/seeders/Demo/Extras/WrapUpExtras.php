<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\Accounting\LockPeriod;
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
        // The package is built by a job; on any other queue it would be run by a worker against another database.
        config(['queue.default' => 'sync']);

        try {
            app(LockPeriod::class)($c->user, $c->company, CarbonImmutable::parse('2026-09-30'));
            app(CreateArchivePackage::class)($c->user, $c->fiscalYear);
        } catch (ValidationException $exception) {
            $c->warn(collect($exception->errors())->flatten()->implode(' '));
        }

        Artisan::call('ledger:verify', ['company' => (string) $c->company->id]);
    }
}

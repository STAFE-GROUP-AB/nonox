<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;

/**
 * What a DemoExtra gets: the company being built (the current company while
 * it runs), its owner, its entry in DemoIndustries and the dates. During
 * schedule(), on() puts work in the builder's queue, which runs everything
 * in date order so voucher numbers follow the dates.
 *
 * @phpstan-import-type Demo from DemoIndustries
 */
final readonly class DemoContext
{
    /**
     * @param  Demo  $demo
     * @param  Closure(string, string): void  $warn  level and message
     * @param  (Closure(CarbonImmutable, int, Closure(): mixed): void)|null  $schedule
     */
    public function __construct(
        public string $slug,
        public array $demo,
        public User $user,
        public Company $company,
        public FiscalYear $fiscalYear,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $today,
        private Closure $warn,
        private ?Closure $schedule = null,
    ) {}

    /**
     * Runs $event on $date, after the day's events with a lower $order
     * (10 sales, 30 invoices, 40 customer payments, 50 supplier payments,
     * 60 salaries, 90 the VAT return of a period that ends that day).
     *
     * @param  Closure(): mixed  $event
     */
    public function on(CarbonImmutable $date, int $order, Closure $event): void
    {
        if ($this->schedule === null) {
            throw new RuntimeException('on() is only available in schedule().');
        }

        ($this->schedule)($date, $order, $event);
    }

    public function warn(string $message): void
    {
        ($this->warn)('warn', "{$this->company->name}: {$message}");
    }
}

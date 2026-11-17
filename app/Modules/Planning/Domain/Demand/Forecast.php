<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Demand;

/**
 * One day's forecast of sales and how it came about, so anyone can
 * recompute it by hand: the average of the listed days, the same holiday
 * last year, a person's override, or none.
 */
final readonly class Forecast
{
    public const string AVERAGE = 'AVERAGE';

    public const string HOLIDAY = 'HOLIDAY';

    public const string OVERRIDE = 'OVERRIDE';

    public const string NONE = 'NONE';

    /**
     * @param  list<string>  $basis  the days (Y-m-d) the figure is computed from
     * @param  ?int  $computedOre  the figure before an override
     */
    public function __construct(
        public string $date,
        public ?int $amountOre,
        public string $kind,
        public array $basis,
        public ?string $holiday = null,
        public ?int $computedOre = null,
        public ?string $reason = null,
    ) {}

    public function isOverride(): bool
    {
        return $this->kind === self::OVERRIDE;
    }
}

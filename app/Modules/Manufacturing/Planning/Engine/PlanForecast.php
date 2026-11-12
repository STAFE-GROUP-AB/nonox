<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

use App\Modules\Manufacturing\Domain\ForecastStatus;
use App\Modules\Manufacturing\Domain\WaitsFor;

/**
 * The plan's answer for one production order (ADR-071 §13.3 steps 5–6):
 * when its material is ready, when it starts and finishes, the forecast
 * delivery date against the promised date, the traffic light, what it
 * waits for, and flags (assumed_material, beyond_horizon, no_resource,
 * lock_broken, lock_out_of_order).
 */
final readonly class PlanForecast
{
    public const string ASSUMED_MATERIAL = 'assumed_material';

    public const string BEYOND_HORIZON = 'beyond_horizon';

    public const string NO_RESOURCE = 'no_resource';

    public const string LOCK_BROKEN = 'lock_broken';

    public const string LOCK_OUT_OF_ORDER = 'lock_out_of_order';

    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public int $orderId,
        public ?string $salesDocumentId,
        public ?string $materialReadyOn,
        public ?int $startAt,
        public ?int $finishAt,
        public ?string $deliveryOn,
        public ?string $promisedOn,
        public ForecastStatus $status,
        public WaitsFor $waitsFor,
        public array $flags = [],
    ) {}

    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning;

use App\Modules\Manufacturing\Planning\Engine\PlanInput;

/**
 * A loaded snapshot: the scheduler's input, each production order's
 * customer (for the impact of a deviation), and the dates each person is
 * away (shown only as "Frånvarande").
 */
final readonly class PlanData
{
    /**
     * @param  array<int, ?int>  $customers  production order id → customer id
     * @param  array<int, list<string>>  $absentDays  employee id → dates
     */
    public function __construct(
        public PlanInput $input,
        public array $customers,
        public array $absentDays,
    ) {}
}

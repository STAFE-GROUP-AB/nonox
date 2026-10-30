<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Rules;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A rule the run needs has no verified version for the payment date. The run
 * is blocked; there is never a fallback value (PAYROLL_MODULE.md B.6).
 */
final class MissingPayrollRuleException extends RuntimeException
{
    public function __construct(public readonly string $key, public readonly CarbonImmutable $date, public readonly bool $unverified)
    {
        parent::__construct($unverified
            ? "Payroll rule {$key} for {$date->toDateString()} exists but is not verified."
            : "Payroll rule {$key} has no version for {$date->toDateString()}.");
    }
}

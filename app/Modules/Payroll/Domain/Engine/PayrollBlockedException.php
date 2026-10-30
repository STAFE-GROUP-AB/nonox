<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

use RuntimeException;

/**
 * The payslip cannot be calculated: information or a supported method is
 * missing. Shown on the run's checklist; the run is blocked, never guessed.
 */
final class PayrollBlockedException extends RuntimeException {}

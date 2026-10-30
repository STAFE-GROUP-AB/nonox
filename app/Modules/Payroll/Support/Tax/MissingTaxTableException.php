<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Tax;

use RuntimeException;

/**
 * No imported tax table covers the year, table and column. The run is
 * blocked (PAYROLL_MODULE.md B.7).
 */
final class MissingTaxTableException extends RuntimeException {}

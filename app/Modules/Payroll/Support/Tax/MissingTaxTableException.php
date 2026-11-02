<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Tax;

use RuntimeException;

/**
 * No imported tax table covers the year, table and column. The run is
 * blocked (PAYROLL_MODULE.md B.7). When no table at all is imported for the
 * year (yearMissing), the message says so once for the whole run; the
 * tables come from Skatteverket each December.
 */
final class MissingTaxTableException extends RuntimeException
{
    public function __construct(public readonly int $year, public readonly int $table, public readonly int $column, public readonly bool $yearMissing)
    {
        $message = $yearMissing
            ? __('Skattetabellerna för :year är inte inlästa ännu. Skatteverket publicerar dem i december året före; se Lön → Regler.', ['year' => $year])
            : __('Skattetabell :table kolumn :column finns inte bland de inlästa tabellerna för :year.', ['table' => $table, 'column' => $column, 'year' => $year]);

        parent::__construct(is_string($message) ? $message : "No tax tables for {$year}.");
    }
}

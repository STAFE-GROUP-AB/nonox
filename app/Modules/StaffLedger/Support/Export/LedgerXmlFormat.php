<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support\Export;

/**
 * An XML format for handing the staff ledger to Skatteverket. Bound in the
 * container, so the provisional format can be replaced by Skatteverket's
 * schema without touching the callers.
 *
 * TODO(SPEC): Skatteverket's schemalager publishes a schema for the
 * construction industry's ledger (v1.1) but, as of 2026-10-31, none for the
 * industries in prop. 2025/26:282 from 2027. Replace ProvisionalLedgerXml
 * with an implementation of that schema when it is published.
 */
interface LedgerXmlFormat
{
    public function render(ControlVisitReport $report): string;

    /** Path to the XSD the output validates against. */
    public function schemaPath(): string;

    /** A short name and version, for file names and the audit log. */
    public function version(): string;
}

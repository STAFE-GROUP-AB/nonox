<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\Company\ReportingFramework;
use App\Domain\Company\ReportingFrameworkRules;
use App\Models\Company;
use App\Models\FiscalYear;

/**
 * Owner decision 16: a K2 company confirms the K2 conditions as amended by
 * BFNAR 2025:2 (BFNAR 2016:10 punkt 1.1A-1.1C) once, the first time it
 * closes a fiscal year that starts after 2025-12-31. The texts are the ones
 * shown when K2 is chosen (ReportingFrameworkRules::conditions).
 *
 * TODO(legal-verify): that one confirmation per company is enough, rather
 * than one per fiscal year; this follows the owner's decision.
 */
final class K2Reconfirmation
{
    /** The last day before BFNAR 2025:2 applies to a fiscal year. */
    public const string LAST_OLD_DAY = '2025-12-31';

    /** Whether the company must have confirmed the conditions for the year, confirmed or not. */
    public static function applies(Company $company, FiscalYear $fiscalYear): bool
    {
        return $company->reporting_framework === ReportingFramework::K2
            && $fiscalYear->start_date->toDateString() > self::LAST_OLD_DAY;
    }

    /** Whether the company has still to confirm before the year can be closed. */
    public static function isPending(Company $company, FiscalYear $fiscalYear): bool
    {
        return self::applies($company, $fiscalYear) && $company->k2_conditions_confirmed_at === null;
    }

    /** The conditions to show, as for the framework choice. */
    public static function conditions(Company $company): string
    {
        return (string) ReportingFrameworkRules::conditions($company->legal_form, ReportingFramework::K2);
    }
}

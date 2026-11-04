<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use App\Models\Company;
use App\Modules\Crm\CrmSettings;

/**
 * What the company allows its users' calendars (ADR-049), from CRM's
 * settings. The company is the controller of the CRM data; a user chooses
 * within what it allows.
 */
final class CalendarRules
{
    /** Details (subject, place, note, people) may go into a calendar when the user chooses them. */
    public static function detailsAllowed(Company $company): bool
    {
        return CrmSettings::for($company)['calendar_details'] === 1;
    }

    /** Users may connect their own Google or Microsoft calendar: a provider is configured and the company said yes. */
    public static function syncAllowed(Company $company): bool
    {
        return app(CalendarProviders::class)->anyConfigured() && CrmSettings::for($company)['calendar_sync'] === 1;
    }
}

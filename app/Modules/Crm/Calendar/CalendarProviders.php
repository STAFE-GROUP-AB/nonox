<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use App\Modules\Crm\Calendar\Providers\CalendarProvider;
use App\Modules\Crm\Calendar\Providers\GoogleCalendarProvider;
use App\Modules\Crm\Calendar\Providers\MicrosoftGraphProvider;

/**
 * The calendar providers (ADR-049). Only a configured one (client id and
 * secret in config/services.php) is offered; with none configured the
 * two-way sync is invisible and nothing calls Google or Microsoft.
 */
final class CalendarProviders
{
    public function for(CalendarProviderKey $key): CalendarProvider
    {
        return match ($key) {
            CalendarProviderKey::Google => app(GoogleCalendarProvider::class),
            CalendarProviderKey::Microsoft => app(MicrosoftGraphProvider::class),
        };
    }

    /** @return list<CalendarProvider> */
    public function configured(): array
    {
        return array_values(array_filter(
            array_map(fn (CalendarProviderKey $key): CalendarProvider => $this->for($key), CalendarProviderKey::cases()),
            fn (CalendarProvider $provider): bool => $provider->isConfigured(),
        ));
    }

    public function anyConfigured(): bool
    {
        return $this->configured() !== [];
    }
}

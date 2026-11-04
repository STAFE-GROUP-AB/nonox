<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Providers;

use App\Modules\Crm\Calendar\CalendarEntry;
use App\Modules\Crm\Calendar\CalendarProviderKey;

/**
 * A user's outside calendar (ADR-049): OAuth 2.0 with PKCE, the user's
 * writable calendars, writing NONOX's events, and reading back changes to
 * those events only. Implementations talk plain HTTP through Laravel's
 * client, so tests fake every request; no SDK.
 *
 * A provider without a client id and secret in config/services.php is not
 * configured: no screen offers it and nothing calls it.
 *
 * Errors: CalendarProviderException; `reconnect` is set when the grant is
 * gone (the user must connect again), `resync` when the change cursor has
 * expired (read everything again).
 */
interface CalendarProvider
{
    public function key(): CalendarProviderKey;

    public function isConfigured(): bool;

    /** @return list<string> the scopes asked for */
    public function scopes(): array;

    public function authorizationUrl(string $state, string $codeChallenge): string;

    public function exchangeCode(string $code, string $codeVerifier): OAuthTokens;

    public function refresh(string $refreshToken): OAuthTokens;

    /** Revokes the grant at the provider. False when the provider has no such call for one token. */
    public function revoke(string $token): bool;

    /** Which account signed in, and whether it is an organisation's (not a private one). */
    public function account(OAuthTokens $tokens): RemoteAccount;

    /** @return list<RemoteCalendar> the calendars the user may write to */
    public function calendars(string $accessToken): array;

    /** Creates the event, or replaces it when $externalId is given and still exists. Returns the event's id. */
    public function upsert(string $accessToken, string $calendarId, CalendarEntry $entry, ?string $externalId): string;

    /** Removes the event; one that is already gone is not an error. */
    public function delete(string $accessToken, string $calendarId, string $externalId): void;

    /** Changes since $cursor (null: from the start), with the cursor for the next read. */
    public function changes(string $accessToken, string $calendarId, ?string $cursor): RemoteChanges;
}

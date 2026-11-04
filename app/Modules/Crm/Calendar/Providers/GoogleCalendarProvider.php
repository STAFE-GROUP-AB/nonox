<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Providers;

use App\Modules\Crm\Calendar\CalendarEntry;
use App\Modules\Crm\Calendar\CalendarProviderKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Calendar API v3 (ADR-049), dormant until services.google_calendar
 * has a client id and secret. OAuth 2.0 authorization code with PKCE and
 * offline access; events are written with a private extended property that
 * names the NONOX activity; changes are read with the events list's
 * incremental sync token (a 410 means: read everything again).
 *
 * Scopes: calendar.events (write NONOX's events), calendar.calendarlist
 * .readonly (let the user pick a calendar), openid and email (show which
 * account is connected). Both calendar scopes are "sensitive" at Google: a
 * public app needs Google's verification (ADR-049, owner actions).
 */
final class GoogleCalendarProvider implements CalendarProvider
{
    public const string AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const string TOKEN = 'https://oauth2.googleapis.com/token';

    public const string REVOKE = 'https://oauth2.googleapis.com/revoke';

    public const string USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

    public const string API = 'https://www.googleapis.com/calendar/v3';

    /** The private extended property that marks an event as NONOX's. */
    public const string MARKER = 'nonoxActivity';

    public function key(): CalendarProviderKey
    {
        return CalendarProviderKey::Google;
    }

    public function isConfigured(): bool
    {
        return filled(config('services.google_calendar.client_id')) && filled(config('services.google_calendar.client_secret'));
    }

    public function scopes(): array
    {
        return [
            'openid',
            'email',
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        ];
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return self::AUTHORIZE.'?'.http_build_query([
            'client_id' => (string) config('services.google_calendar.client_id'),
            'redirect_uri' => $this->redirect(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            // A refresh token, so the sync runs without the user present.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $codeVerifier): OAuthTokens
    {
        return OAuthTokens::fromResponse($this->json(Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'client_id' => (string) config('services.google_calendar.client_id'),
            'client_secret' => (string) config('services.google_calendar.client_secret'),
            'redirect_uri' => $this->redirect(),
        ]), 'token'));
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        return OAuthTokens::fromResponse($this->json(Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => (string) config('services.google_calendar.client_id'),
            'client_secret' => (string) config('services.google_calendar.client_secret'),
        ]), 'refresh'));
    }

    public function revoke(string $token): bool
    {
        // Revoking either token ends the whole grant. An already invalid token answers 400: nothing left to revoke.
        $response = Http::asForm()->timeout(10)->post(self::REVOKE, ['token' => $token]);

        return $response->successful() || $response->status() === 400;
    }

    /** Private Google accounts: consumer domains, and any account without a Workspace domain (hd). */
    public const array CONSUMER_DOMAINS = ['gmail.com', 'googlemail.com'];

    public function account(OAuthTokens $tokens): RemoteAccount
    {
        $json = $this->json($this->api($tokens->accessToken)->get(self::USERINFO), 'userinfo');
        $email = is_string($json['email'] ?? null) ? $json['email'] : null;
        $domain = is_string($json['hd'] ?? null) ? mb_strtolower($json['hd']) : '';

        return new RemoteAccount($email, $domain !== '' && ! in_array($domain, self::CONSUMER_DOMAINS, true));
    }

    /**
     * The event id NONOX gives a new event: base32hex (here lower-case hex)
     * from the activity's UID, so a retried insert finds the same event
     * instead of making a second one.
     */
    public static function eventId(CalendarEntry $entry): string
    {
        return substr(hash('sha256', $entry->uid), 0, 40);
    }

    public function calendars(string $accessToken): array
    {
        $json = $this->json($this->api($accessToken)->get(self::API.'/users/me/calendarList', ['minAccessRole' => 'writer', 'maxResults' => 250]), 'calendarList');
        $calendars = [];

        foreach (is_array($json['items'] ?? null) ? $json['items'] : [] as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }

            $calendars[] = new RemoteCalendar($item['id'], (string) ($item['summaryOverride'] ?? $item['summary'] ?? $item['id']), (bool) ($item['primary'] ?? false));
        }

        return $calendars;
    }

    public function upsert(string $accessToken, string $calendarId, CalendarEntry $entry, ?string $externalId): string
    {
        $body = [
            'summary' => $entry->summary,
            'description' => $entry->description,
            'location' => $entry->location,
            'start' => ['dateTime' => $entry->start->setTimezone('Europe/Stockholm')->toRfc3339String(), 'timeZone' => 'Europe/Stockholm'],
            'end' => ['dateTime' => $entry->end->setTimezone('Europe/Stockholm')->toRfc3339String(), 'timeZone' => 'Europe/Stockholm'],
            // Restores an event that was cancelled in the calendar when NONOX writes it again.
            'status' => 'confirmed',
            'visibility' => 'private',
            'source' => $entry->url === null ? null : ['title' => 'NONOX', 'url' => $entry->url],
            'extendedProperties' => ['private' => [self::MARKER => $entry->uid]],
        ];
        $events = self::API.'/calendars/'.rawurlencode($calendarId).'/events';

        if ($externalId !== null) {
            $response = $this->api($accessToken)->patch($events.'/'.rawurlencode($externalId), $body);

            // Removed in the calendar: written again as a new event.
            if (! in_array($response->status(), [404, 410], true)) {
                return (string) ($this->json($response, 'events.patch')['id'] ?? $externalId);
            }
        }

        // No sequence is sent: Google keeps its own and refuses a lower one after an edit in the calendar.
        $id = self::eventId($entry);
        $response = $this->api($accessToken)->post($events, [...$body, 'id' => $id]);

        // The event already exists (a retried insert, or one cancelled earlier): it is updated instead.
        if ($response->status() === 409) {
            return (string) ($this->json($this->api($accessToken)->patch($events.'/'.rawurlencode($id), $body), 'events.patch')['id'] ?? $id);
        }

        $created = $this->json($response, 'events.insert')['id'] ?? null;

        if (! is_string($created) || $created === '') {
            throw new CalendarProviderException('Google answered an insert without an event id.');
        }

        return $created;
    }

    public function delete(string $accessToken, string $calendarId, string $externalId): void
    {
        $response = $this->api($accessToken)->delete(self::API.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($externalId));

        if (in_array($response->status(), [404, 410], true)) {
            return;
        }

        $this->json($response, 'events.delete');
    }

    public function changes(string $accessToken, string $calendarId, ?string $cursor): RemoteChanges
    {
        $changes = [];
        $pageToken = null;
        $url = self::API.'/calendars/'.rawurlencode($calendarId).'/events';

        do {
            // The sync token cannot be combined with filters; the caller keeps only NONOX's own events.
            $query = array_filter([
                'syncToken' => $cursor,
                'pageToken' => $pageToken,
                'showDeleted' => 'true',
                'maxResults' => 250,
            ], fn (mixed $value): bool => $value !== null);
            $response = $this->api($accessToken)->get($url, $query);

            if ($response->status() === 410) {
                throw new CalendarProviderException('Google: the sync token expired.', resync: true);
            }

            $json = $this->json($response, 'events.list');

            foreach (is_array($json['items'] ?? null) ? $json['items'] : [] as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                    continue;
                }

                $cancelled = ($item['status'] ?? null) === 'cancelled';
                $start = $item['start']['dateTime'] ?? null;
                $end = $item['end']['dateTime'] ?? null;

                $changes[] = new RemoteChange(
                    $item['id'],
                    $cancelled,
                    ! $cancelled && is_string($start) ? CarbonImmutable::parse($start) : null,
                    ! $cancelled && is_string($end) ? CarbonImmutable::parse($end) : null,
                );
            }

            $pageToken = is_string($json['nextPageToken'] ?? null) ? $json['nextPageToken'] : null;
            $next = is_string($json['nextSyncToken'] ?? null) ? $json['nextSyncToken'] : null;
        } while ($pageToken !== null);

        return new RemoteChanges($changes, $next ?? $cursor);
    }

    private function redirect(): string
    {
        return (string) (config('services.google_calendar.redirect') ?: route('crm.calendar.callback', ['provider' => 'google']));
    }

    private function api(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(20);
    }

    /** @return array<string, mixed> */
    private function json(Response $response, string $call): array
    {
        if ($response->status() === 401 || ($response->status() === 400 && ($response->json('error') === 'invalid_grant'))) {
            throw new CalendarProviderException("Google {$call}: the grant is no longer valid.", reconnect: true);
        }

        if (! $response->successful()) {
            throw new CalendarProviderException("Google {$call}: HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}

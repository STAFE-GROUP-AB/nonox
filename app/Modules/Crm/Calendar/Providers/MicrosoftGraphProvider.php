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
 * Microsoft Graph v1.0 calendars (ADR-049): Outlook, Microsoft 365 and
 * Outlook.com. Dormant until services.microsoft_graph has a client id and
 * secret. OAuth 2.0 authorization code with PKCE on the Microsoft identity
 * platform (tenant "organizations" by default: work and school accounts; "common" adds personal accounts, refused unless the company allows them); events
 * are written to the chosen calendar, and changes are read with the
 * calendar view's delta query, whose deltaLink is the cursor.
 *
 * Scopes: Calendars.ReadWrite, User.Read, offline_access, openid, email.
 * Graph has no call that revokes one delegated grant (only all of a
 * user's sign-in sessions), so disconnecting deletes the tokens here and
 * tells the user where to remove the app's consent.
 */
final class MicrosoftGraphProvider implements CalendarProvider
{
    public const string LOGIN = 'https://login.microsoftonline.com';

    public const string API = 'https://graph.microsoft.com/v1.0';

    public function key(): CalendarProviderKey
    {
        return CalendarProviderKey::Microsoft;
    }

    public function isConfigured(): bool
    {
        return filled(config('services.microsoft_graph.client_id')) && filled(config('services.microsoft_graph.client_secret'));
    }

    public function scopes(): array
    {
        return ['openid', 'email', 'offline_access', 'User.Read', 'Calendars.ReadWrite'];
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return $this->endpoint('authorize').'?'.http_build_query([
            'client_id' => (string) config('services.microsoft_graph.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirect(),
            'response_mode' => 'query',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $codeVerifier): OAuthTokens
    {
        return OAuthTokens::fromResponse($this->json(Http::asForm()->acceptJson()->timeout(15)->post($this->endpoint('token'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'client_id' => (string) config('services.microsoft_graph.client_id'),
            'client_secret' => (string) config('services.microsoft_graph.client_secret'),
            'redirect_uri' => $this->redirect(),
            'scope' => implode(' ', $this->scopes()),
        ]), 'token'));
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        return OAuthTokens::fromResponse($this->json(Http::asForm()->acceptJson()->timeout(15)->post($this->endpoint('token'), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => (string) config('services.microsoft_graph.client_id'),
            'client_secret' => (string) config('services.microsoft_graph.client_secret'),
            'scope' => implode(' ', $this->scopes()),
        ]), 'refresh'));
    }

    public function revoke(string $token): bool
    {
        return false;
    }

    /** The tenant of personal Microsoft accounts (Outlook.com, Hotmail). */
    public const string CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    public function account(OAuthTokens $tokens): RemoteAccount
    {
        $json = $this->json($this->api($tokens->accessToken)->get(self::API.'/me', ['$select' => 'mail,userPrincipalName']), 'me');
        $email = $json['mail'] ?? $json['userPrincipalName'] ?? null;
        $tenant = $tokens->claims()['tid'] ?? null;

        // Without a tenant claim the account cannot be shown to be an organisation's.
        return new RemoteAccount(is_string($email) ? $email : null, is_string($tenant) && $tenant !== '' && $tenant !== self::CONSUMER_TENANT);
    }

    public function calendars(string $accessToken): array
    {
        $json = $this->json($this->api($accessToken)->get(self::API.'/me/calendars', ['$select' => 'id,name,canEdit,isDefaultCalendar', '$top' => 100]), 'calendars');
        $calendars = [];

        foreach (is_array($json['value'] ?? null) ? $json['value'] : [] as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || ! (bool) ($item['canEdit'] ?? false)) {
                continue;
            }

            $calendars[] = new RemoteCalendar($item['id'], (string) ($item['name'] ?? $item['id']), (bool) ($item['isDefaultCalendar'] ?? false));
        }

        return $calendars;
    }

    public function upsert(string $accessToken, string $calendarId, CalendarEntry $entry, ?string $externalId): string
    {
        $body = [
            'subject' => $entry->summary,
            'body' => ['contentType' => 'text', 'content' => (string) $entry->description],
            'start' => ['dateTime' => $entry->start->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $entry->end->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'location' => ['displayName' => (string) $entry->location],
            'sensitivity' => 'private',
            'showAs' => 'busy',
        ];

        if ($externalId !== null) {
            $response = $this->api($accessToken)->patch(self::API.'/me/events/'.rawurlencode($externalId), $body);

            if ($response->status() !== 404) {
                return (string) ($this->json($response, 'events.patch')['id'] ?? $externalId);
            }
        }

        // transactionId makes a retried create return the same event instead of a second one.
        $body['transactionId'] = $entry->uid.'-'.$entry->sequence;
        $id = $this->json($this->api($accessToken)->post(self::API.'/me/calendars/'.rawurlencode($calendarId).'/events', $body), 'events.create')['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new CalendarProviderException('Graph answered a create without an event id.');
        }

        return $id;
    }

    public function delete(string $accessToken, string $calendarId, string $externalId): void
    {
        $response = $this->api($accessToken)->delete(self::API.'/me/events/'.rawurlencode($externalId));

        if ($response->status() === 404) {
            return;
        }

        $this->json($response, 'events.delete');
    }

    public function changes(string $accessToken, string $calendarId, ?string $cursor): RemoteChanges
    {
        $now = CarbonImmutable::now()->utc();
        // The first read sets the window; the deltaLink keeps it.
        $url = $cursor ?? self::API.'/me/calendars/'.rawurlencode($calendarId).'/calendarView/delta?'.http_build_query([
            'startDateTime' => $now->subDays(30)->format('Y-m-d\TH:i:s\Z'),
            'endDateTime' => $now->addDays(366)->format('Y-m-d\TH:i:s\Z'),
        ]);
        $changes = [];
        $deltaLink = null;

        while ($url !== null) {
            // Times in UTC, so no Windows zone names need translating.
            $response = $this->api($accessToken)->withHeaders(['Prefer' => 'outlook.timezone="UTC", odata.maxpagesize=200'])->get($url);

            if ($response->status() === 410) {
                throw new CalendarProviderException('Graph: the delta token expired.', resync: true);
            }

            $json = $this->json($response, 'calendarView.delta');

            foreach (is_array($json['value'] ?? null) ? $json['value'] : [] as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                    continue;
                }

                $cancelled = array_key_exists('@removed', $item) || (bool) ($item['isCancelled'] ?? false);
                $start = $item['start']['dateTime'] ?? null;
                $end = $item['end']['dateTime'] ?? null;

                $changes[] = new RemoteChange(
                    $item['id'],
                    $cancelled,
                    ! $cancelled && is_string($start) ? CarbonImmutable::parse($start, 'UTC') : null,
                    ! $cancelled && is_string($end) ? CarbonImmutable::parse($end, 'UTC') : null,
                );
            }

            $url = is_string($json['@odata.nextLink'] ?? null) ? $json['@odata.nextLink'] : null;
            $deltaLink = is_string($json['@odata.deltaLink'] ?? null) ? $json['@odata.deltaLink'] : $deltaLink;
        }

        return new RemoteChanges($changes, $deltaLink ?? $cursor);
    }

    private function endpoint(string $kind): string
    {
        $tenant = (string) (config('services.microsoft_graph.tenant') ?: 'organizations');

        return self::LOGIN.'/'.rawurlencode($tenant).'/oauth2/v2.0/'.$kind;
    }

    private function redirect(): string
    {
        return (string) (config('services.microsoft_graph.redirect') ?: route('crm.calendar.callback', ['provider' => 'microsoft']));
    }

    private function api(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(20);
    }

    /** @return array<string, mixed> */
    private function json(Response $response, string $call): array
    {
        if ($response->status() === 401 || ($response->status() === 400 && $response->json('error') === 'invalid_grant')) {
            throw new CalendarProviderException("Graph {$call}: the grant is no longer valid.", reconnect: true);
        }

        if (! $response->successful()) {
            throw new CalendarProviderException("Graph {$call}: HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}

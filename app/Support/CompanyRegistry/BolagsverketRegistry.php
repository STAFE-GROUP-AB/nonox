<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Bolagsverket's free "API för värdefulla datamängder" (ADR-035), version
 * v1 of its OpenAPI 3.0.3 specification: an OAuth 2.0 client-credentials
 * token from the portal, then POST /organisationer with the field
 * identitetsbeteckning (ten digits, or twelve for a personal number).
 *
 * An unknown number is answered with HTTP 200 and the error type
 * ORGANISATION_FINNS_EJ on each part; a part whose source is unavailable
 * carries OTILLGANGLIG_UPPGIFTSKALLA or TIMEOUT.
 */
final class BolagsverketRegistry implements CompanyRegistry
{
    public const string KEY = 'bolagsverket';

    private const string SCOPE = 'vardefulla-datamangder:read';

    /** @var array<string, array{token: string, api: string}> */
    private const array ENVIRONMENTS = [
        'prod' => [
            'token' => 'https://portal.api.bolagsverket.se/oauth2/token',
            'api' => 'https://gw.api.bolagsverket.se/vardefulla-datamangder/v1/',
        ],
        'test' => [
            'token' => 'https://portal-accept2.api.bolagsverket.se/oauth2/token',
            'api' => 'https://gw-accept2.api.bolagsverket.se/vardefulla-datamangder/v1/',
        ],
    ];

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $environment = 'prod',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.company_registry.bolagsverket.client_id'),
            (string) config('services.company_registry.bolagsverket.client_secret'),
            (string) config('services.company_registry.bolagsverket.environment', 'prod'),
        );
    }

    public static function isConfigured(): bool
    {
        return filled(config('services.company_registry.bolagsverket.client_id'))
            && filled(config('services.company_registry.bolagsverket.client_secret'))
            && array_key_exists((string) config('services.company_registry.bolagsverket.environment', 'prod'), self::ENVIRONMENTS);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function sourceName(): string
    {
        return 'Bolagsverket';
    }

    public function find(string $identity): array
    {
        $response = $this->post('organisationer', ['identitetsbeteckning' => $identity]);

        if (in_array($response->status(), [400, 404], true)) {
            return [];
        }

        if (! $response->successful() || ! is_array($response->json('organisationer'))) {
            throw new RegistryUnavailable("Bolagsverket answered HTTP {$response->status()}.");
        }

        $companies = [];

        foreach ((array) $response->json('organisationer') as $organisation) {
            if (! is_array($organisation)) {
                continue;
            }

            $company = $this->company($organisation);

            if ($company !== null) {
                $companies[] = $company;
            }
        }

        return $companies;
    }

    /** @param array<string, mixed> $organisation */
    private function company(array $organisation): ?RegisteredCompany
    {
        $names = (array) ($organisation['organisationsnamn'] ?? []);
        $nameError = data_get($names, 'fel.typ');

        if ($nameError === 'ORGANISATION_FINNS_EJ') {
            return null;
        }

        if (in_array($nameError, ['OTILLGANGLIG_UPPGIFTSKALLA', 'TIMEOUT'], true)) {
            throw new RegistryUnavailable("Bolagsverket could not deliver the name ({$nameError}).");
        }

        $list = array_values(array_filter((array) ($names['organisationsnamnLista'] ?? []), 'is_array'));
        $name = collect($list)->first(fn (array $entry): bool => data_get($entry, 'organisationsnamntyp.kod') === 'FORETAGSNAMN') ?? ($list[0] ?? null);

        if (! is_array($name) || ! is_string($name['namn'] ?? null) || trim($name['namn']) === '') {
            return null;
        }

        $address = data_get($organisation, 'postadressOrganisation.fel') === null
            ? (array) data_get($organisation, 'postadressOrganisation.postadress', [])
            : [];

        $proceedings = (array) (data_get($organisation, 'pagaendeAvvecklingsEllerOmstruktureringsforfarande.pagaendeAvvecklingsEllerOmstruktureringsforfarandeLista')
            ?? data_get($organisation, 'pagandeAvvecklingsEllerOmstruktureringsforfarande.pagandeAvvecklingsEllerOmstruktureringsforfarandeLista')
            ?? []);

        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $date = fn (mixed $value): ?string => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;

        return new RegisteredCompany(
            name: trim($name['namn']),
            legalFormCode: $text(data_get($organisation, 'organisationsform.kod')),
            legalFormText: $text(data_get($organisation, 'organisationsform.klartext')),
            street: $text($address['utdelningsadress'] ?? null),
            coAddress: $text($address['coAdress'] ?? null),
            postalCode: $text($address['postnummer'] ?? null),
            city: $text($address['postort'] ?? null),
            country: $text($address['land'] ?? null),
            registeredOn: $date(data_get($organisation, 'organisationsdatum.registreringsdatum')),
            deregisteredOn: $date(data_get($organisation, 'avregistreradOrganisation.avregistreringsdatum')),
            proceedings: array_values(array_filter(array_map(
                fn (mixed $entry): ?string => is_array($entry) ? $text($entry['klartext'] ?? null) : null,
                $proceedings,
            ))),
        );
    }

    /** @param array<string, string> $body */
    private function post(string $path, array $body): Response
    {
        foreach ([false, true] as $renewToken) {
            try {
                $response = Http::baseUrl($this->endpoints()['api'])
                    ->withToken($this->token($renewToken))
                    ->withHeaders(['X-Request-Id' => (string) Str::uuid()])
                    ->acceptJson()
                    ->timeout(10)
                    ->post($path, $body);
            } catch (ConnectionException $exception) {
                throw new RegistryUnavailable('Bolagsverket did not answer.', 0, $exception);
            }

            if ($response->status() !== 401) {
                return $response;
            }
        }

        throw new RegistryUnavailable('Bolagsverket refused the access token.');
    }

    private function token(bool $renew): string
    {
        $cacheKey = 'company-registry:bolagsverket:token:'.$this->environment.':'.hash('sha256', $this->clientId);

        if ($renew) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->acceptJson()
                ->timeout(10)
                ->post($this->endpoints()['token'], ['grant_type' => 'client_credentials', 'scope' => self::SCOPE]);
        } catch (ConnectionException $exception) {
            throw new RegistryUnavailable('Bolagsverket\'s token endpoint did not answer.', 0, $exception);
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RegistryUnavailable("Bolagsverket refused the client credentials (HTTP {$response->status()}).");
        }

        $lifetime = max(60, (int) ($response->json('expires_in') ?? 3600) - 60);
        Cache::put($cacheKey, $token, $lifetime);

        return $token;
    }

    /** @return array{token: string, api: string} */
    private function endpoints(): array
    {
        return self::ENVIRONMENTS[$this->environment] ?? throw new RegistryUnavailable("Unknown Bolagsverket environment {$this->environment}.");
    }
}

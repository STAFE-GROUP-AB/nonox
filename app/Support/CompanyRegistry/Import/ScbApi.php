<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry\Import;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * SCB's company register API, "SCB:s allmänna företagsregister API" v1
 * (ADR-047). Free, but every call needs a personal API key in X-API-Key
 * (services.company_register.scb_api_key, from registreraafr.scb.se): this
 * source is dormant until an operator has one. It walks
 * /juridiskaenheter/full with cursor pagination (5 000 rows per call) and
 * adds what the bulk files lack: employee and turnover size classes, seat
 * municipality and county, phone, e-mail, the phone and e-mail opt-outs and
 * the status at Bolagsverket. It also loads the code tables for
 * municipalities and statuses.
 *
 * SCB allows 5 calls per second per key and answers 429 with Retry-After
 * above that; this source makes at most 4 and waits out a 429. The API is
 * down for 30 minutes from 04:00 every night.
 */
final class ScbApi implements RegistrySource
{
    public const string KEY = 'scb-api';

    public const int PAGE = 5000;

    private string $cursor = '';

    public function __construct(
        private readonly string $url,
        private readonly string $key,
    ) {}

    public static function isConfigured(): bool
    {
        return is_string(config('services.company_register.scb_api_key')) && config('services.company_register.scb_api_key') !== '';
    }

    public static function make(): self
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('SCB:s API kräver en API-nyckel (SCB_AFR_API_KEY). Den är gratis och söks på registreraafr.scb.se.');
        }

        return new self(rtrim((string) config('services.company_register.scb_api_url'), '/').'/', (string) config('services.company_register.scb_api_key'));
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function version(): string
    {
        // The API is updated every night and has no version of its own: one run per day.
        return 'api:'.CarbonImmutable::now('Europe/Stockholm')->toDateString();
    }

    public function createsRows(): bool
    {
        return true;
    }

    public function updateColumns(): array
    {
        return ['name', 'legal_form', 'scb_status', 'sni_code', 'sni_division', 'sni_codes', 'address_line1', 'co_address', 'postal_code', 'city', 'municipality_code', 'county_code', 'employee_class', 'turnover_class', 'turnover_year', 'phone', 'email', 'bolagsverket_status', 'advertising_block', 'phone_block', 'email_block', 'fetched_at', 'last_import_id', 'sources'];
    }

    public function position(): string
    {
        return $this->cursor;
    }

    public function rows(?string $resumeFrom): iterable
    {
        $this->loadCodes();
        $this->cursor = (string) $resumeFrom;
        $fetchedAt = CarbonImmutable::now();

        do {
            $query = ['limit' => self::PAGE, ...($this->cursor === '' ? [] : ['cursorId' => $this->cursor])];
            $page = $this->get('juridiskaenheter/full', $query)->json();
            $entities = is_array($page['jes'] ?? null) ? $page['jes'] : [];

            foreach ($entities as $entity) {
                yield is_array($entity) ? $this->row($entity, $fetchedAt) : null;
            }

            $more = (bool) ($page['pagination']['hasMore'] ?? false);
            $next = (string) ($page['pagination']['nextCursorId'] ?? '');
            $this->cursor = $next;
        } while ($more && $next !== '');
    }

    /** @param  array<string, mixed>  $entity */
    private function row(array $entity, CarbonImmutable $fetchedAt): ?RegistryRow
    {
        $identity = (string) ($entity['peOrgNr'] ?? '');
        $form = trim((string) ($entity['jurform'] ?? ''));

        if (! preg_match('/^16(\d{10})$/', $identity, $match) || (int) $match[1][2] < 2 || in_array($form, ScbBulkFile::EXCLUDED_FORMS, true)) {
            return null;
        }

        $name = trim((string) ($entity['namn'] ?? ''));

        if ($name === '') {
            return null;
        }

        $branches = is_array($entity['naringsgrenar'] ?? null) ? $entity['naringsgrenar'] : [];
        usort($branches, fn (mixed $a, mixed $b): int => (int) ($a['rangordning'] ?? 9) <=> (int) ($b['rangordning'] ?? 9));
        $sni = array_values(array_filter(array_map(fn (mixed $branch): string => trim((string) ($branch['naringsgren'] ?? '')), $branches), fn (string $code): bool => preg_match('/^\d{5}$/', $code) === 1));
        $address = is_array($entity['postAdress'] ?? null) ? $entity['postAdress'] : [];
        $int = fn (string $key): ?int => is_numeric($entity[$key] ?? null) ? (int) $entity[$key] : null;
        $text = fn (mixed $value, int $max = 255): ?string => trim((string) $value) === '' ? null : mb_substr(trim((string) $value), 0, $max);
        $employees = $int('anstKl');
        $turnover = $int('omsKl');

        return new RegistryRow($match[1], [
            'name' => mb_substr($name, 0, 255),
            'legal_form' => $form === '' ? null : substr($form, 0, 2),
            'scb_status' => $int('ftgStat'),
            'sni_code' => $sni[0] ?? null,
            'sni_division' => isset($sni[0]) ? substr($sni[0], 0, 2) : null,
            'sni_codes' => $sni,
            'address_line1' => $text($address['gatuAdress'] ?? null),
            'co_address' => $text($address['coAdress'] ?? null),
            'postal_code' => $text(preg_replace('/\D/', '', (string) ($address['postNr'] ?? '')), 10),
            'city' => $text($address['postOrt'] ?? null),
            'municipality_code' => $text($entity['kommunSate'] ?? null, 4),
            'county_code' => $text($entity['lanSate'] ?? null, 2),
            // 0 is "uppgift saknas" for employees; turnover class 0 is a real class (under 1 tkr).
            'employee_class' => $employees === null || $employees === 0 ? null : $employees,
            'turnover_class' => $turnover,
            'turnover_year' => $int('omsAr'),
            'phone' => $text($entity['tel'] ?? null, 50),
            'email' => $text($entity['epost'] ?? null),
            'bolagsverket_status' => $int('bolStat'),
            'advertising_block' => $int('reklamSparrTyp') === 4,
            'phone_block' => $int('telefonSparrTyp'),
            'email_block' => $int('epostSparrTyp') === 2,
            'fetched_at' => $fetchedAt,
        ]);
    }

    /** Municipality and status names from the API's code tables, into company_registry_codes. */
    private function loadCodes(): void
    {
        foreach (['kommunkoder' => 'municipality', 'bolstatkoder' => 'bolagsverket_status'] as $table => $kind) {
            $codes = $this->get("kodtabeller/{$table}")->json();

            if (! is_array($codes)) {
                continue;
            }

            $rows = [];

            foreach ($codes as $code) {
                if (is_array($code) && isset($code['kod'], $code['klartext'])) {
                    $rows[] = ['kind' => $kind, 'code' => (string) $code['kod'], 'name' => mb_substr((string) $code['klartext'], 0, 255), 'created_at' => now(), 'updated_at' => now()];
                }
            }

            if ($rows !== []) {
                DB::table('company_registry_codes')->upsert($rows, ['kind', 'code'], ['name', 'updated_at']);
            }
        }
    }

    /** @param  array<string, mixed>  $query */
    private function get(string $path, array $query = []): Response
    {
        for ($attempt = 1; ; $attempt++) {
            // At most four calls a second, under SCB's five per key.
            Sleep::usleep(250_000);
            $response = Http::withHeaders(['X-API-Key' => $this->key])->acceptJson()->timeout(120)->get($this->url.$path, $query);

            if ($response->status() === 429 && $attempt < 6) {
                Sleep::for(min(120, max(1, (int) $response->header('Retry-After'))))->seconds();

                continue;
            }

            // The nightly update window (04:00–04:30): wait it out, a minute at a time, for up to 40 minutes.
            if ($response->status() === 503 && $attempt < 40) {
                Sleep::for(60)->seconds();

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException("SCB:s API svarade {$response->status()} på {$path}.");
            }

            return $response;
        }
    }
}

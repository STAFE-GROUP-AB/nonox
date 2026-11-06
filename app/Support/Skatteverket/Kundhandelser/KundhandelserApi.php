<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Kundhandelser;

use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketClient;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Skatteverket\SkatteverketLink;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Skatteverket's API Kundhändelser v4 (RAML 4.0.5, scope kundhandelser;
 * ADR-054 stage 4): the customer events about a legal person, "something
 * has happened in a matter you have with Skatteverket", each saying who
 * "has the ball".
 *
 * One operation, POST /foretagochorganisationer/kundhandelser/v4/hamta,
 * with the body {kund: {identifierare, typ}}. Without startDatum
 * Skatteverket answers two years back, without slutDatum up to now, and
 * without kundhandelseTyper every type the person may see (RAML schema and
 * Tjänstebeskrivning 4.1.1.3); NONOX sends none of them, so the company gets
 * all its events. "anvandare" must not be sent: Skatteverket takes the person
 * from the sign-in.
 *
 * The trait icc-standard-request lists Content-Type, Accept, client_id,
 * client_secret, Authorization and the correlation header
 * "skv_client_correlation_id"; the call carries only those (Accept-Language
 * is in the service description but not in the RAML, so it is left out).
 *
 * @phpstan-type Event array{id: string, type: string, producer: string, occurredAt: ?string, title: string, description: string, language: string, requiresAction: bool, closed: bool, dueDate: ?string, links: list<array{url: string, text: ?string, skatteverket: bool}>, kopplingsId: ?string, skatteslag: ?string, kundamne: list<string>, korrespondensReferens: ?string, notice: ?string}
 */
final class KundhandelserApi
{
    public const string HEADER = 'skv_client_correlation_id';

    /** The kund type for a legal person; the terms cover legal persons only (Allmänna villkor 1.1). */
    public const string ORGANISATION = 'Organisationsnummer';

    /** Defensive caps: Skatteverket's texts are far shorter, and nothing is shortened below these. */
    private const int TEXT_LIMIT = 20000;

    private const int LINK_LIMIT = 10;

    /** The error statuses whose body NONOX reads itself (icc-standard-errors: {message}, 503 as plain text). */
    private const array ANSWERS = [400, 401, 403, 404, 405, 406, 415, 429, 500, 503, 504];

    public function __construct(private readonly string $accessToken) {}

    /**
     * Hämta kundhändelser for a legal person, identified by the twelve-digit
     * "16" form (Redovisare).
     *
     * @return list<Event>
     *
     * @throws SkatteverketFailed
     */
    public function fetch(string $identifierare): array
    {
        $response = SkatteverketClient::send(
            $this->accessToken,
            'POST',
            Skatteverket::apiHost().'/foretagochorganisationer/kundhandelser/v4/hamta',
            ['kund' => ['identifierare' => $identifierare, 'typ' => self::ORGANISATION]],
            correlationHeader: self::HEADER,
            answers: self::ANSWERS,
        );

        if (! $response->successful()) {
            throw KundhandelserErrors::from($response);
        }

        $events = [];
        $rows = $response->json('kundhandelser');

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $event = self::event($row);

            if ($event !== null) {
                $events[$event['id']] = $event;
            }
        }

        return array_values($events);
    }

    /**
     * @param  array<mixed>  $row
     * @return ?Event
     */
    private static function event(array $row): ?array
    {
        $id = self::short($row['kundhandelseId'] ?? null);

        // kundhandelseId is required and makes the event unique (schema); an event without one cannot be kept apart from the next.
        if ($id === '') {
            return null;
        }

        $extra = is_array($row['utokadInformation'] ?? null) ? $row['utokadInformation'] : [];
        $references = is_array($row['referenser'] ?? null) ? $row['referenser'] : [];

        return [
            'id' => $id,
            'type' => self::short($row['kundhandelseTyp'] ?? null),
            'producer' => self::short($row['producent'] ?? null),
            'occurredAt' => self::dateTime($row['tidpunkt'] ?? null),
            'title' => self::text($row['rubrik'] ?? null),
            'description' => self::text($row['beskrivning'] ?? null),
            'language' => mb_substr(self::short($row['sprak'] ?? null), 0, 16),
            'requiresAction' => ($row['producentarendetKraverKundatgard'] ?? false) === true,
            'closed' => ($row['producentarendetKlart'] ?? false) === true,
            'dueDate' => self::date($extra['forfallodatum'] ?? null),
            'links' => self::links($references['etjanst'] ?? null),
            // The extended fields the end user reads with the event (utokadInformation, Tjänstebeskrivning 4.1.2.5); foretagsform is the company's own and is left out.
            'kopplingsId' => self::optional($extra['kopplingsId'] ?? null),
            'skatteslag' => self::optional($extra['skatteslag'] ?? null),
            'kundamne' => self::list($extra['kundamne'] ?? null),
            'korrespondensReferens' => self::optional($extra['korrespondensReferens'] ?? null),
            'notice' => self::text($extra['aviseringsmeddelande'] ?? null) ?: null,
        ];
    }

    /**
     * The e-service links of an event (referenser.etjanst: a string, an
     * object {url, lanktext} or a list of either). A link to skatteverket.se
     * (SkatteverketLink) is shown as a link; any other is kept so the event
     * is shown in full, but only as text, never clickable.
     *
     * @return list<array{url: string, text: ?string, skatteverket: bool}>
     */
    private static function links(mixed $reference): array
    {
        $items = is_array($reference) && array_is_list($reference) ? $reference : [$reference];
        $links = [];

        foreach ($items as $item) {
            $url = is_array($item) ? ($item['url'] ?? null) : $item;
            $url = is_string($url) ? self::clean(trim($url)) : '';

            if ($url === '' || mb_strlen($url) > 2000) {
                continue;
            }

            $text = is_array($item) ? self::short($item['lanktext'] ?? null) : '';
            $links[$url] = ['url' => $url, 'text' => $text === '' ? null : $text, 'skatteverket' => SkatteverketLink::isSkatteverket($url)];
        }

        return array_slice(array_values($links), 0, self::LINK_LIMIT);
    }

    /** @return list<string> kundamne: a string in the schema, 0..n in the service description */
    private static function list(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_slice(array_values(array_filter(array_map(fn (mixed $item): string => self::short($item), $values))), 0, self::LINK_LIMIT);
    }

    private static function optional(mixed $value): ?string
    {
        $value = self::short($value);

        return $value === '' ? null : $value;
    }

    /** NUL bytes are dropped: PostgreSQL refuses them in text, and they carry nothing to read. */
    private static function clean(string $value): string
    {
        return str_replace("\0", '', $value);
    }

    /** Skatteverket's free text as it came, shown escaped; never cut below TEXT_LIMIT. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(mb_substr(self::clean($value), 0, self::TEXT_LIMIT)) : '';
    }

    private static function short(mixed $value): string
    {
        return is_string($value) ? trim(mb_substr(self::clean($value), 0, 255)) : '';
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value ? $value : null;
    }

    /** RFC 3339 (Tjänstebeskrivning 4.1.2.3); a time without a zone is read as Swedish time. */
    private static function dateTime(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, (string) config('app.timezone'))->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}

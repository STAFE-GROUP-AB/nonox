<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Skattekonto;

use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketClient;
use App\Support\Skatteverket\SkatteverketFailed;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Skatteverket's API Skattekonto v2 (RAML 2.1.0, scope ska; ADR-054 stage
 * 3): the balance (saldo), earlier and coming transactions (transaktioner)
 * and the payment blocks the company has asked for (utbetalningssparrar).
 * Every amount is whole kronor; a minus sign is a debt.
 *
 * The fourth operation, utbetalningskonto, is not used: it returns the
 * bank account Skatteverket pays refunds to, which reconciling the tax
 * account does not need (GDPR art. 5.1 c, Allmänna villkor 7.1).
 *
 * The RAML trait "traceable" names the correlation header
 * "SKV-client_correlationid"; the call carries only the headers the RAML
 * lists (Accept, Authorization, client_id, client_secret and that one).
 */
final class SkattekontoApi
{
    public const string HEADER = 'SKV-client_correlationid';

    /** The error statuses Skattekonto answers with felkod and felmeddelande (schemas/fel.json). */
    private const array ANSWERS = [400, 403, 404, 500, 503];

    public function __construct(private readonly string $accessToken) {}

    /**
     * Hämta aktuellt dagssaldo: without a date, the balance when the tax
     * account was last updated.
     *
     * @return array{skatteverket: int, kronofogden: int, interestSkatteverket: int, interestKronofogden: int, ocr: string, nextReconciliation: ?string, updatedAt: ?string, information: list<string>}
     *
     * @throws SkatteverketFailed
     */
    public function balance(string $omfragad, ?CarbonImmutable $date = null): array
    {
        $body = $this->get("{$omfragad}/saldo", $date === null ? [] : ['datum' => $date->toDateString()]);

        return [
            'skatteverket' => self::int($body['saldoSkatteverket'] ?? 0),
            'kronofogden' => self::int($body['saldoKronofogden'] ?? 0),
            'interestSkatteverket' => self::int($body['rantaSkatteverket'] ?? 0),
            'interestKronofogden' => self::int($body['rantaKronofogden'] ?? 0),
            'ocr' => preg_replace('/\D/', '', mb_substr((string) ($body['ocrNummer'] ?? ''), 0, 25)) ?? '',
            'nextReconciliation' => self::date($body['nastaAvstamningsdatum'] ?? null),
            'updatedAt' => self::dateTime($body['senastUppdaterad'] ?? null),
            'information' => self::information($body),
        ];
    }

    /**
     * Hämta tidigare och kommande transaktioner from a date. Without one
     * Skatteverket answers 18 months back, and never more than 915 days;
     * "from" is the date Skatteverket used.
     *
     * @return array{from: ?string, updatedAt: ?string, information: list<string>, earlier: list<array{id: int, date: string, interestDate: ?string, text: string, skatteverket: ?int, kronofogden: ?int}>, coming: list<array{id: ?int, date: string, dueDate: ?string, decidedAt: ?string, text: string, skatteverket: ?int, kronofogden: ?int}>}
     *
     * @throws SkatteverketFailed
     */
    public function transactions(string $omfragad, ?CarbonImmutable $from = null): array
    {
        $body = $this->get("{$omfragad}/transaktioner", $from === null ? [] : ['datumFrom' => $from->toDateString()]);
        $earlier = [];
        $coming = [];

        foreach (self::rows($body['tidigareTransaktioner'] ?? []) as $row) {
            $date = self::date($row['transaktionsdatum'] ?? null);
            $id = $row['transaktionsidentitet'] ?? null;

            // A booked transaction always has its identity and date (transaktioner.json); one without is not shown as booked.
            if ($date === null || ! is_int($id)) {
                continue;
            }

            $earlier[] = [
                'id' => $id,
                'date' => $date,
                'interestDate' => self::date($row['ranteberakningsdatum'] ?? null),
                'text' => self::text($row['transaktionstext'] ?? ''),
                'skatteverket' => self::optionalInt($row['beloppSkatteverket'] ?? null),
                'kronofogden' => self::optionalInt($row['beloppKronofogden'] ?? null),
            ];
        }

        foreach (self::rows($body['kommandeTransaktioner'] ?? []) as $row) {
            $date = self::date($row['transaktionsdatum'] ?? null);

            if ($date === null) {
                continue;
            }

            $coming[] = [
                // Missing on coming debited preliminary tax (Tjänstebeskrivning 4.1.3.1).
                'id' => is_int($row['transaktionsidentitet'] ?? null) ? $row['transaktionsidentitet'] : null,
                'date' => $date,
                'dueDate' => self::date($row['forfallodatum'] ?? null),
                'decidedAt' => self::date($row['beslutsdatum'] ?? null),
                'text' => self::text($row['transaktionstext'] ?? ''),
                'skatteverket' => self::optionalInt($row['beloppSkatteverket'] ?? null),
                'kronofogden' => self::optionalInt($row['beloppKronofogden'] ?? null),
            ];
        }

        usort($earlier, fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        usort($coming, fn (array $a, array $b): int => [$a['dueDate'] ?? $a['date'], $a['date']] <=> [$b['dueDate'] ?? $b['date'], $b['date']]);

        return [
            'from' => self::date($body['datumFrom'] ?? null),
            'updatedAt' => self::dateTime($body['senastUppdaterad'] ?? null),
            'information' => self::information($body),
            'earlier' => $earlier,
            'coming' => $coming,
        ];
    }

    /**
     * Hämta utbetalningsspärrar på egen begäran: money the company has asked
     * Skatteverket to leave on the tax account.
     *
     * @return array{information: list<string>, blocks: list<array{id: int, from: string, until: ?string, amount: ?int, total: bool}>}
     *
     * @throws SkatteverketFailed
     */
    public function paymentBlocks(string $omfragad): array
    {
        $body = $this->get("{$omfragad}/utbetalningssparrar");
        $blocks = [];

        foreach (self::rows($body['utbetalningssparrinformation'] ?? []) as $row) {
            $from = self::date($row['datumFrom'] ?? null);

            if ($from === null || ! is_int($row['sparridentitet'] ?? null)) {
                continue;
            }

            $blocks[] = [
                'id' => $row['sparridentitet'],
                'from' => $from,
                'until' => self::date($row['datumTom'] ?? null),
                'amount' => self::optionalInt($row['sparrbelopp'] ?? null),
                'total' => ($row['totalsparr'] ?? false) === true,
            ];
        }

        return ['information' => self::information($body), 'blocks' => $blocks];
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     *
     * @throws SkatteverketFailed
     */
    private function get(string $path, array $query = []): array
    {
        $url = Skatteverket::apiHost()."/beskattning/skattekonto/v2/skattekonton/{$path}".($query === [] ? '' : '?'.http_build_query($query));
        $response = SkatteverketClient::send($this->accessToken, 'GET', $url, correlationHeader: self::HEADER, answers: self::ANSWERS);

        if (! $response->successful()) {
            throw SkattekontoErrors::from($response);
        }

        return (array) $response->json();
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    /**
     * Skatteverket's information texts (at most 200 characters each), shown
     * when fetched and never stored.
     *
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private static function information(array $body): array
    {
        $texts = is_array($body['informationstext'] ?? null) ? $body['informationstext'] : [];

        return array_values(array_filter(array_map(fn (mixed $text): string => is_string($text) ? trim(mb_substr(strip_tags($text), 0, 200)) : '', $texts)));
    }

    private static function text(mixed $text): string
    {
        return is_string($text) ? trim(mb_substr(strip_tags($text), 0, 50)) : '';
    }

    private static function int(mixed $value): int
    {
        return self::optionalInt($value) ?? 0;
    }

    private static function optionalInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private static function dateTime(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) !== 1) {
            return null;
        }

        // TODO(provider-verify): the schema writes the time with "Z" (UTC), the service description without; a time without a zone is read as Swedish time.
        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}

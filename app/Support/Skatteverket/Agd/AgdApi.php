<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Agd;

use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketClient;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Skatteverket\SkatteverketLink;
use Illuminate\Http\Client\Response;

/**
 * Skatteverket's APIs for the arbetsgivardeklaration (ADR-054, stage 2):
 *
 * - Arbetsgivardeklaration inlämning v1 (RAML 1.7.7, library 1.0.13, scope
 *   agd): read the AGI XML into the company's own space ("Eget utrymme"),
 *   fetch the check result, save or cancel an upload with errors, remove a
 *   saved upload, and create the review link to Mina sidor.
 * - Arbetsgivardeklaration hantera redovisningsperiod v1 (RAML 1.2.8,
 *   library 1.0.26, scope agdredovisningperiod): the period's status, its
 *   events and its receipts.
 *
 * The two APIs name their correlation header differently (their RAML
 * traits): "SKV-client_correlationid" and "skv_client_correlation_id".
 * Signing never happens here: the review link leads to Mina sidor.
 *
 * Personal data Skatteverket returns (the uploader's and signer's
 * personnummer, contact names, the payees' identities in the check result)
 * is dropped here and never returned to the caller.
 */
final class AgdApi
{
    public const string FILING_HEADER = 'SKV-client_correlationid';

    public const string PERIOD_HEADER = 'skv_client_correlation_id';

    /** Kontrollresultat "status" (kontrollresultat.json). */
    public const string PROCESSING = 'PROCESSING';

    public const string DONE_SUCCESS = 'DONE_SUCCESS';

    public const string DONE_FAILED = 'DONE_FAILED';

    public const string DONE_REJECTED = 'DONE_REJECTED';

    public function __construct(private readonly string $accessToken) {}

    /**
     * Läs in underlag: the XML as it is; Skatteverket answers with the
     * upload's id and checks it asynchronously.
     *
     * @throws SkatteverketFailed
     */
    public function upload(string $xml): int
    {
        // Allmänna villkor 8 a d: when nothing was saved the person is told so, and why.
        $notSaved = __('Ingenting har sparats i företagets eget utrymme hos Skatteverket.');
        $maybeReceived = __('Skatteverket svarade inte i tid, så NONOX vet inte om filen kom fram. Den kan ha tagits emot ändå. Titta under Arbetsgivardeklaration på Mina sidor, eller vänta en stund och hämta status från Skatteverket, innan du skickar filen igen.');

        try {
            $id = SkatteverketClient::send($this->accessToken, 'POST', self::filing('underlag'), correlationHeader: self::FILING_HEADER, raw: $xml, contentType: 'application/xml', noAnswer: $maybeReceived)->json('inlamningId');
        } catch (SkatteverketFailed $exception) {
            throw AgdErrors::explain($exception, $notSaved);
        }

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw new SkatteverketFailed(502, __('Skatteverket tog emot filen men svarade inte med något inlämnings-id, så NONOX kan inte följa den. Titta under Arbetsgivardeklaration på Mina sidor innan du skickar filen igen.'), SkatteverketFailed::NO_ANSWER);
        }

        return (int) $id;
    }

    /**
     * Hämta kontrollresultat. While Skatteverket is still checking, only the
     * status PROCESSING comes back.
     *
     * @return array{status: string, reportStatus: ?string, receivedAt: ?string, hu: ?int, iu: ?int, errors: ?int, warnings: ?int, messages: list<array{kind: string, text: string, specification: ?int}>}
     *
     * @throws SkatteverketFailed
     */
    public function result(int $uploadId): array
    {
        return AgdResult::from((array) self::explained(fn () => SkatteverketClient::send($this->accessToken, 'GET', self::filing("underlag/{$uploadId}/kontrollresultat"), correlationHeader: self::FILING_HEADER))->json());
    }

    /**
     * Spara inlämning: keeps an upload with errors in the own space anyway.
     * The errors must be put right before the declaration can be signed.
     *
     * @throws SkatteverketFailed
     */
    public function save(int $uploadId): void
    {
        self::explained(fn () => SkatteverketClient::send($this->accessToken, 'POST', self::filing("underlag/{$uploadId}/spara"), correlationHeader: self::FILING_HEADER, contentType: 'application/json'));
    }

    /**
     * Avbryt inlämning: removes an upload with errors that has not been saved.
     *
     * @throws SkatteverketFailed
     */
    public function cancel(int $uploadId): void
    {
        self::explained(fn () => SkatteverketClient::send($this->accessToken, 'DELETE', self::filing("underlag/{$uploadId}"), correlationHeader: self::FILING_HEADER));
    }

    /**
     * Ta bort sparad inlämning: removes what one saved upload put into the
     * period's own space.
     *
     * @throws SkatteverketFailed
     */
    public function remove(string $employer, string $period, int $uploadId): void
    {
        self::explained(fn () => SkatteverketClient::send($this->accessToken, 'DELETE', self::filing("arbetsgivare/{$employer}/redovisningsperioder/{$period}/inlamningar/{$uploadId}"), correlationHeader: self::FILING_HEADER));
    }

    /**
     * Skapa granskningsunderlag: prepares the period for review and returns
     * the link to Mina sidor. Skatteverket answers 409 with a state when it
     * cannot (RECEIVING, CALCULATING, INCORRECT_DATA with a link to the error
     * report, SIGNING, SIGNED).
     *
     * @return array{ready: bool, state: string, link: ?string}
     *
     * @throws SkatteverketFailed
     */
    public function review(string $employer, string $period): array
    {
        // lasPeriod is left out (= false): NONOX never locks the period, so a corrected file can always be read in (ADR-054 stage 2).
        $response = self::explained(fn () => SkatteverketClient::send($this->accessToken, 'POST', self::filing("arbetsgivare/{$employer}/redovisningsperioder/{$period}/skapaGranskningsunderlag"), correlationHeader: self::FILING_HEADER, contentType: 'application/json', answers: [409]));
        $state = strtoupper(trim((string) $response->json('tillstand')));

        // Tjänstebeskrivning 1.23 spells the state INCORRRECT_DATA in one place and INCORRECT_DATA in another.
        if ($state === 'INCORRRECT_DATA') {
            $state = 'INCORRECT_DATA';
        }

        $link = SkatteverketLink::orNull($response->json('link'));

        return [
            // Ready only with a usable review link in a state that has one.
            'ready' => $response->successful() && $link !== null && in_array($state, AgdErrors::REVIEW_STATES, true),
            'state' => mb_substr($state, 0, 30),
            'link' => $link,
        ];
    }

    /**
     * Hämta grunddata: the period's state in the own space and its dates.
     * Null when Skatteverket has nothing for the period (404).
     *
     * @return ?array{state: string, signed: bool, correcting: bool, deadline: ?string, paymentDate: ?string, underlag: ?string, iuOk: int, iuErrors: int, iuWarnings: int}
     *
     * @throws SkatteverketFailed
     */
    public function period(string $employer, string $period): ?array
    {
        try {
            $data = (array) (SkatteverketClient::send($this->accessToken, 'GET', self::periodUrl("{$employer}/redovisningsperioder/{$period}/grunddata"), correlationHeader: self::PERIOD_HEADER)->json('grunddata') ?? []);
        } catch (SkatteverketFailed $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }

        $dates = (array) ($data['period'] ?? []);

        return [
            'state' => mb_substr((string) ($data['statusEgetUtrymme'] ?? ''), 0, 30),
            'signed' => ($data['signerad'] ?? false) === true,
            'correcting' => ($data['omprovningIniterad'] ?? false) === true,
            'deadline' => self::date($dates['deklarationsdatum'] ?? null),
            'paymentDate' => self::date($dates['betalningsdatum'] ?? null),
            'underlag' => is_string($data['statusUnderlag'] ?? null) ? mb_substr($data['statusUnderlag'], 0, 10) : null,
            'iuOk' => (int) ($data['antalIUstatusOK'] ?? 0),
            'iuErrors' => (int) ($data['antalIUstatusFel'] ?? 0),
            'iuWarnings' => (int) ($data['antalIUstatusVarning'] ?? 0),
        ];
    }

    /**
     * Hämta händelser, newest first. Who caused each event
     * (handelseUtfordAv, a personnummer) is dropped.
     *
     * @return list<array{at: string, text: string}>
     *
     * @throws SkatteverketFailed
     */
    public function events(string $employer, string $period): array
    {
        $rows = self::listOr404(fn (): mixed => SkatteverketClient::send($this->accessToken, 'GET', self::periodUrl("{$employer}/redovisningsperioder/{$period}/handelser"), correlationHeader: self::PERIOD_HEADER)->json('handelser'));

        $events = array_map(fn (array $row): array => [
            'at' => (string) ($row['handelsetid'] ?? ''),
            'text' => mb_substr(strip_tags((string) ($row['handelsetext'] ?? '')), 0, 300),
        ], $rows);

        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $events;
    }

    /**
     * Hämta kvittenser: one per time the period has been signed. Who signed
     * (signeradAv) and the contact persons are dropped.
     *
     * @return list<array{id: string, signedAt: string, individuals: ?int}>
     *
     * @throws SkatteverketFailed
     */
    public function receipts(string $employer, string $period): array
    {
        $rows = self::listOr404(fn (): mixed => SkatteverketClient::send($this->accessToken, 'GET', self::periodUrl("{$employer}/redovisningsperioder/{$period}/kvittenser"), correlationHeader: self::PERIOD_HEADER)->json('kvittenser'));

        return array_map(fn (array $row): array => [
            'id' => mb_substr((string) ($row['uuidKvittens'] ?? ''), 0, 64),
            'signedAt' => (string) ($row['signeradTid'] ?? ''),
            'individuals' => is_int($row['underlag']['antalIu'] ?? null) ? $row['underlag']['antalIu'] : null,
        ], $rows);
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws SkatteverketFailed
     */
    private static function explained(callable $call): Response
    {
        try {
            return $call();
        } catch (SkatteverketFailed $exception) {
            throw AgdErrors::explain($exception);
        }
    }

    /**
     * @param  callable(): mixed  $call
     * @return list<array<string, mixed>>
     */
    private static function listOr404(callable $call): array
    {
        try {
            $rows = $call();
        } catch (SkatteverketFailed $exception) {
            if ($exception->status === 404) {
                return [];
            }

            throw $exception;
        }

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private static function filing(string $path): string
    {
        return Skatteverket::apiHost()."/arbetsgivardeklaration/inlamning/v1/{$path}";
    }

    private static function periodUrl(string $path): string
    {
        return Skatteverket::apiHost()."/arbetsgivardeklaration/hanteraredovisningsperiod/v1/arbetsgivare/{$path}";
    }
}

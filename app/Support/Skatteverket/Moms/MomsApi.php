<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Moms;

use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketClient;
use App\Support\Skatteverket\SkatteverketFailed;

/**
 * Skatteverket's API Momsdeklaration v1 (RAML 1.0.25, library 1.0.38).
 * The period is the last month of the VAT period as ÅÅÅÅMM; the company is
 * its twelve-digit "redovisare". Signing never happens here: locking
 * returns a link to Skatteverket's own signing page.
 */
final class MomsApi
{
    public function __construct(private readonly string $accessToken) {}

    /**
     * Checks a draft without saving it.
     *
     * @param  array<string, int>  $momsuppgift
     * @return array{status: string, resultat: list<array{status: string, kod: string, beskrivning: string}>}
     *
     * @throws SkatteverketFailed
     */
    public function check(string $redovisare, string $period, array $momsuppgift): array
    {
        return self::result(SkatteverketClient::send($this->accessToken, 'POST', self::url("kontrollera/{$redovisare}/{$period}"), $momsuppgift)->json());
    }

    /**
     * Checks and saves a draft in the company's own space at Skatteverket;
     * with $lock it is also locked for signing when the check allows it.
     *
     * @param  array<string, int>  $momsuppgift
     * @return array{saved: bool, locked: bool, check: array{status: string, resultat: list<array{status: string, kod: string, beskrivning: string}>}, signingLink: ?string}
     *
     * @throws SkatteverketFailed
     */
    public function save(string $redovisare, string $period, array $momsuppgift, bool $lock): array
    {
        $url = self::url("utkast/{$redovisare}/{$period}").($lock ? '?lock=true' : '');
        $body = (array) SkatteverketClient::send($this->accessToken, 'POST', $url, $momsuppgift)->json();
        $locked = ($body['last'] ?? false) === true;
        $link = $body['signeringsLank'] ?? null;

        return [
            'saved' => ($body['sparad'] ?? false) === true,
            'locked' => $locked,
            'check' => self::result($body['kontrollResultat'] ?? []),
            // Show the link only when the draft is locked (Tjänstebeskrivning 2.5.3).
            'signingLink' => $locked && is_string($link) && self::isSkatteverketLink($link) ? $link : null,
        ];
    }

    /**
     * Unlocks a locked draft so a new one can be saved over it (Lås upp).
     * Skatteverket answers 409 while the draft is being signed.
     *
     * @throws SkatteverketFailed
     */
    public function unlock(string $redovisare, string $period): void
    {
        SkatteverketClient::send($this->accessToken, 'DELETE', self::url("las/{$redovisare}/{$period}"));
    }

    /**
     * What has been filed for the period: the receipt number and when.
     *
     * @return list<array{receipt: string, submittedAt: string, boxes: array<int, int>}>
     *
     * @throws SkatteverketFailed
     */
    public function submitted(string $redovisare, string $period): array
    {
        try {
            $rows = SkatteverketClient::send($this->accessToken, 'GET', self::url("inlamnat/{$redovisare}/{$period}"))->json();
        } catch (SkatteverketFailed $exception) {
            // Nothing filed for the period yet.
            if ($exception->status === 404) {
                return [];
            }

            throw $exception;
        }

        return array_values(array_map(fn (array $row): array => [
            'receipt' => (string) ($row['kvittensnummer'] ?? ''),
            'submittedAt' => (string) ($row['inlamnadTidpunkt'] ?? ''),
            'boxes' => MomsPayload::boxes((array) ($row['momsuppgift'] ?? [])),
        ], array_filter(is_array($rows) ? $rows : [], 'is_array')));
    }

    /**
     * The amounts Skatteverket has decided for the period (the latest decision).
     *
     * @return array{decidedAt: string, boxes: array<int, int>}
     *
     * @throws SkatteverketFailed
     */
    public function decided(string $redovisare, string $period): array
    {
        $body = (array) SkatteverketClient::send($this->accessToken, 'GET', self::url("beslutat/{$redovisare}/{$period}"))->json();

        return [
            'decidedAt' => (string) ($body['beslutadTidpunkt'] ?? ''),
            'boxes' => MomsPayload::boxes((array) ($body['momsuppgift'] ?? [])),
        ];
    }

    /**
     * Only a link to Skatteverket's own site is shown to the user. Browsers
     * read a backslash as a slash and drop what is before an "@", so a link
     * with either, or with whitespace, is refused before the host is read.
     */
    public static function isSkatteverketLink(string $link): bool
    {
        if (! str_starts_with($link, 'https://') || preg_match('/[\\\\@\s\x00-\x1f\x7f]/', $link) === 1) {
            return false;
        }

        $host = parse_url($link, PHP_URL_HOST);

        return is_string($host) && preg_match('/^[a-z0-9.-]+$/', $host) === 1 && ($host === 'skatteverket.se' || str_ends_with($host, '.skatteverket.se'));
    }

    private static function url(string $path): string
    {
        return Skatteverket::apiHost()."/momsdeklaration/v1/{$path}";
    }

    /**
     * @return array{status: string, resultat: list<array{status: string, kod: string, beskrivning: string}>}
     */
    private static function result(mixed $body): array
    {
        $body = is_array($body) ? $body : [];
        $rows = [];

        foreach ((array) ($body['resultat'] ?? []) as $row) {
            if (is_array($row)) {
                $rows[] = [
                    'status' => (string) ($row['status'] ?? ''),
                    'kod' => (string) ($row['kod'] ?? ''),
                    'beskrivning' => mb_substr((string) ($row['beskrivning'] ?? ''), 0, 500),
                ];
            }
        }

        return ['status' => (string) ($body['status'] ?? ''), 'resultat' => $rows];
    }
}

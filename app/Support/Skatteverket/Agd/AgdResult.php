<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Agd;

/**
 * Skatteverket's check result for one upload of the arbetsgivardeklaration
 * (kontrollresultat.json, AGD inlämning library 1.0.13), reduced to what
 * NONOX shows: the status, the counts and Skatteverket's own error texts.
 *
 * Dropped on purpose: who uploaded (inlamnadAv, a personnummer), the
 * technical and employer contacts (names, e-mail, phone) and the identity of
 * the person each error is about (identifierare). The specification number
 * stays, so the user can find the individuppgift.
 */
final class AgdResult
{
    private const int MAX_MESSAGES = 200;

    /**
     * @param  array<mixed>  $body
     * @return array{status: string, reportStatus: ?string, receivedAt: ?string, hu: ?int, iu: ?int, errors: ?int, warnings: ?int, messages: list<array{kind: string, text: string, specification: ?int}>}
     */
    public static function from(array $body): array
    {
        $report = is_array($body['kontrollrapport'] ?? null) ? $body['kontrollrapport'] : [];
        $messages = [];

        // Errors in the file itself: schema validation (FORMAT, technical English texts) and processing; the upload is rejected on them.
        foreach (['valideringsfel' => 'FORMAT', 'bearbetningsfel' => 'STOPP'] as $key => $kind) {
            foreach ((array) ($report[$key] ?? []) as $row) {
                if (is_array($row)) {
                    $messages[] = ['kind' => $kind, 'text' => self::text($row['felmeddelande'] ?? ''), 'specification' => null];
                }
            }
        }

        // Errors per period: STOPP must be put right before signing; ARENDE is a reasonableness check (rimlighetsfel).
        foreach ((array) ($report['redovisningsperioder'] ?? []) as $employer) {
            foreach ((array) (is_array($employer) ? ($employer['perioder'] ?? []) : []) as $period) {
                foreach ((array) (is_array($period) ? ($period['kontrollfel'] ?? []) : []) as $row) {
                    if (is_array($row)) {
                        $messages[] = [
                            'kind' => ($row['felstatus'] ?? '') === 'ARENDE' ? 'ARENDE' : 'STOPP',
                            'text' => self::text($row['felmeddelande'] ?? ''),
                            'specification' => is_int($row['specifikationsnummer'] ?? null) ? $row['specifikationsnummer'] : null,
                        ];
                    }
                }
            }
        }

        return [
            'status' => mb_substr((string) ($body['status'] ?? ''), 0, 20),
            'reportStatus' => is_string($report['status'] ?? null) ? mb_substr($report['status'], 0, 20) : null,
            'receivedAt' => is_string($body['inlamnad'] ?? null) ? $body['inlamnad'] : null,
            'hu' => self::count($report['totaltAntalHU'] ?? null),
            'iu' => self::count($report['totaltAntalIU'] ?? null),
            'errors' => self::count($report['antalFel'] ?? null),
            'warnings' => self::count($report['antalVarningar'] ?? null),
            'messages' => array_slice(array_values(array_filter($messages, fn (array $message): bool => $message['text'] !== '')), 0, self::MAX_MESSAGES),
        ];
    }

    private static function text(mixed $value): string
    {
        return mb_substr(trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5)), 0, 500);
    }

    private static function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}

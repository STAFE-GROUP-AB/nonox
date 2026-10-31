<?php

declare(strict_types=1);

namespace App\Support\Sru;

use Carbon\CarbonImmutable;

/**
 * Writes the two files of an SRU delivery to Skatteverket (Teknisk
 * information om filöverföring): INFO.SRU about the sender and
 * BLANKETTER.SRU with one block per form. ISO 8859-1, one record per line
 * ending in LF, amounts in whole kronor without leading zeros.
 */
final class SruFile
{
    /**
     * @param  array{orgnr: string, name: string, postal_code: string, city: string, address?: ?string, email?: ?string, phone?: ?string}  $sender
     * @param  list<array{form: string, identity: string, name: string, fields: array<int, int|string>}>  $blocks
     * @return array{'INFO.SRU': string, 'BLANKETTER.SRU': string}
     */
    public static function render(array $sender, array $blocks, CarbonImmutable $createdAt, string $program): array
    {
        $info = [
            '#DATABESKRIVNING_START',
            '#PRODUKT SRU',
            '#SKAPAD '.$createdAt->format('Ymd His'),
            '#PROGRAM '.self::text($program),
            '#FILNAMN BLANKETTER.SRU',
            '#DATABESKRIVNING_SLUT',
            '#MEDIELEV_START',
            '#ORGNR '.$sender['orgnr'],
            '#NAMN '.self::text($sender['name']),
        ];

        if (filled($sender['address'] ?? null)) {
            $info[] = '#ADRESS '.self::text((string) $sender['address']);
        }

        $info[] = '#POSTNR '.preg_replace('/\D/', '', $sender['postal_code']);
        $info[] = '#POSTORT '.self::text($sender['city']);

        foreach (['email' => '#EMAIL', 'phone' => '#TELEFON'] as $key => $record) {
            if (filled($sender[$key] ?? null)) {
                $info[] = $record.' '.self::text((string) $sender[$key]);
            }
        }

        $info[] = '#MEDIELEV_SLUT';
        $forms = [];

        foreach ($blocks as $block) {
            $forms[] = '#BLANKETT '.$block['form'];
            $forms[] = '#IDENTITET '.$block['identity'].' '.$createdAt->format('Ymd His');
            $forms[] = '#NAMN '.self::text($block['name']);

            foreach ($block['fields'] as $code => $value) {
                $forms[] = "#UPPGIFT {$code} {$value}";
            }

            $forms[] = '#BLANKETTSLUT';
        }

        $forms[] = '#FIL_SLUT';

        return [
            'INFO.SRU' => self::encode($info),
            'BLANKETTER.SRU' => self::encode($forms),
        ];
    }

    /** @param  list<string>  $lines */
    private static function encode(array $lines): string
    {
        return mb_convert_encoding(implode("\n", $lines)."\n", 'ISO-8859-1', 'UTF-8');
    }

    /** One line of text: no line breaks, no surrounding space. */
    private static function text(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Moms;

/**
 * The VAT return as Skatteverket's "momsuppgift" (Tjänstebeskrivning API för
 * Momsdeklaration 1.0, dokumentversion 1.6, 4.1.1.4): one field per box,
 * whole kronor. Box 49 (summaMoms) is required; the others are sent when
 * they hold an amount. Box 48 is positive for input VAT to deduct.
 */
final class MomsPayload
{
    /** Box number => Skatteverket's field name. */
    public const array FIELDS = [
        5 => 'momspliktigForsaljning',
        6 => 'momspliktigaUttag',
        7 => 'vinstmarginal',
        8 => 'hyresInkomst',
        10 => 'momsForsaljningUtgaendeHog',
        11 => 'momsForsaljningUtgaendeMedel',
        12 => 'momsForsaljningUtgaendeLag',
        20 => 'inkopVarorEU',
        21 => 'inkopTjansterEU',
        22 => 'inkopTjansterUtanforEU',
        23 => 'inkopVarorSE',
        24 => 'inkopTjansterSE',
        30 => 'momsInkopUtgaendeHog',
        31 => 'momsInkopUtgaendeMedel',
        32 => 'momsInkopUtgaendeLag',
        35 => 'forsaljningVarorEU',
        36 => 'forsaljningVarorUtanforEU',
        37 => 'inkopVaror3pHandel',
        38 => 'forsaljningVaror3pHandel',
        39 => 'forsaljningTjansterEU',
        40 => 'ovrigForsaljningTjansterUtanforSE',
        41 => 'forsaljningBskKopareSE',
        42 => 'momsfriForsaljning',
        48 => 'ingaendeMomsAvdrag',
        49 => 'summaMoms',
        50 => 'import',
        60 => 'momsImportUtgaendeHog',
        61 => 'momsImportUtgaendeMedel',
        62 => 'momsImportUtgaendeLag',
    ];

    /**
     * @param  array<int, int>  $boxes  box => whole kronor, as VatReturn::boxes()
     * @return array<string, int>
     */
    public static function from(array $boxes): array
    {
        $payload = [];

        foreach (self::FIELDS as $box => $field) {
            $amount = (int) ($boxes[$box] ?? 0);

            if ($amount !== 0 || $box === 49) {
                $payload[$field] = $amount;
            }
        }

        return $payload;
    }

    /**
     * Skatteverket's momsuppgift back as boxes.
     *
     * @param  array<string, mixed>  $momsuppgift
     * @return array<int, int>
     */
    public static function boxes(array $momsuppgift): array
    {
        $boxes = [];

        foreach (self::FIELDS as $box => $field) {
            if (is_numeric($momsuppgift[$field] ?? null)) {
                $boxes[$box] = (int) $momsuppgift[$field];
            }
        }

        return $boxes;
    }
}

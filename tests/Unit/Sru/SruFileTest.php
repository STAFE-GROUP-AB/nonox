<?php

declare(strict_types=1);

use App\Support\Sru\SruFile;
use Carbon\CarbonImmutable;

it('writes INFO.SRU and BLANKETTER.SRU in the order and encoding Skatteverket reads', function () {
    $files = SruFile::render(
        ['orgnr' => '165560000001', 'name' => 'Kafé Åkern AB', 'postal_code' => '123 45', 'city' => 'Skattstad'],
        [['form' => 'INK2R-2026P4', 'identity' => '165560000001', 'name' => 'Kafé Åkern AB', 'fields' => [7011 => '20260101', 7410 => 100000, 7550 => 5]]],
        CarbonImmutable::parse('2027-03-10 09:45:57'),
        'NONOX',
    );

    expect(mb_convert_encoding($files['INFO.SRU'], 'UTF-8', 'ISO-8859-1'))->toBe(implode("\n", [
        '#DATABESKRIVNING_START', '#PRODUKT SRU', '#SKAPAD 20270310 094557', '#PROGRAM NONOX', '#FILNAMN BLANKETTER.SRU', '#DATABESKRIVNING_SLUT',
        '#MEDIELEV_START', '#ORGNR 165560000001', '#NAMN Kafé Åkern AB', '#POSTNR 12345', '#POSTORT Skattstad', '#MEDIELEV_SLUT',
    ])."\n")
        ->and(mb_convert_encoding($files['BLANKETTER.SRU'], 'UTF-8', 'ISO-8859-1'))->toBe(implode("\n", [
            '#BLANKETT INK2R-2026P4', '#IDENTITET 165560000001 20270310 094557', '#NAMN Kafé Åkern AB',
            '#UPPGIFT 7011 20260101', '#UPPGIFT 7410 100000', '#UPPGIFT 7550 5', '#BLANKETTSLUT', '#FIL_SLUT',
        ])."\n")
        ->and(str_contains($files['INFO.SRU'], "\xC5kern"))->toBeTrue();
});

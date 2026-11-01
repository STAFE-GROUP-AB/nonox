<?php

declare(strict_types=1);

use App\Modules\Payroll\Support\Paxml\PaxmlCodes;
use App\Modules\Payroll\Support\Paxml\PaxmlReader;
use App\Modules\Payroll\Support\Paxml\PayrollFileCsv;
use App\Modules\Payroll\Support\Paxml\PayrollFileLine;
use App\Modules\Payroll\Support\Paxml\UnreadablePayrollFile;

/*
 * The PAXml reader (https://www.paxml.se/{1.0,2.0,2.1,2.2,2.3}/paxml.xsd) and
 * the CSV fallback, on synthetic fixtures. Untrusted XML must never reach
 * the network or expand entities.
 */

function paxmlFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/payroll/paxml/{$name}"));
}

it('reads salary and time transactions from a PAXml 2.2 file in ISO-8859-1', function () {
    $file = (new PaxmlReader)->read(paxmlFixture('testkrogen-2.2.xml'));

    expect($file->format)->toBe('PAXML')
        ->and($file->version)->toBe('2.2')
        ->and($file->program)->toBe('Testschema')
        ->and($file->lines)->toHaveCount(6)
        ->and($file->ignoredSections)->toBe(['schematransaktioner' => 1, 'personal' => 1]);

    [$ob, $overtime, $unknown, $hours, $sick, $ob2] = $file->lines;

    expect($ob)->toEqual(new PayrollFileLine(1, PayrollFileLine::SALARY, '1', null, 'OB1', 'OB-tillägg kväll', '2026-10-05', '2026-10-05', '4.5', '27.50', null))
        ->and($overtime->code)->toBe('ÖT1')
        ->and($overtime->personalNumber)->toBe('198503024567')
        ->and($overtime->amount)->toBe('600.00')
        ->and($unknown->employeeRef)->toBe('99')
        // lonkod (the PAXml standard code) when there is no lonart; datumfrom/datumtom as days.
        ->and($hours->code)->toBe('ARB')
        ->and([$hours->dateFrom, $hours->dateTo])->toBe(['2026-10-01', '2026-10-31'])
        ->and($sick->section)->toBe(PayrollFileLine::TIME)
        ->and(PaxmlCodes::isAbsence($sick))->toBeTrue()
        ->and([$ob2->code, $ob2->quantity, $ob2->rate, $ob2->amount])->toBe(['OB2', '3', null, null]);
});

it('reads a PAXml 1.0 file with dateTime periods', function () {
    $file = (new PaxmlReader)->read(paxmlFixture('testkrogen-1.0.xml'));

    expect($file->version)->toBe('1.0')
        ->and($file->lines)->toHaveCount(2)
        ->and($file->lines[1]->dateFrom)->toBe('2026-10-13')
        ->and($file->lines[1]->quantity)->toBe('1.5');
});

it('refuses a file with a DOCTYPE, so no entity is ever expanded or fetched', function (string $xml) {
    (new PaxmlReader)->read($xml);
})->with([
    'external entity' => '<?xml version="1.0"?><!DOCTYPE paxml [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><paxml><lonetransaktioner><lonetrans anstid="1"><lonart>&xxe;</lonart><antal>1</antal></lonetrans></lonetransaktioner></paxml>',
    'billion laughs' => '<?xml version="1.0"?><!DOCTYPE paxml [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]><paxml><header><version>&c;</version></header></paxml>',
    'external DTD' => '<?xml version="1.0"?><!DOCTYPE paxml SYSTEM "http://example.test/paxml.dtd"><paxml/>',
])->throws(UnreadablePayrollFile::class, 'DOCTYPE');

it('refuses what is not a PAXml file', function (string $xml, string $message) {
    expect(fn () => (new PaxmlReader)->read($xml))->toThrow(UnreadablePayrollFile::class, $message);
})->with([
    'other root' => ['<?xml version="1.0"?><invoice/>', 'paxml'],
    'cut off' => ['<?xml version="1.0"?><paxml><lonetransaktioner><lonetrans anstid="1"><lonart>OB1</lonart>', 'hela filen'],
    'empty' => ['', 'PAXml'],
]);

it('caps the number of transactions', function () {
    $line = '<lonetrans anstid="1"><lonart>OB1</lonart><antal>1</antal></lonetrans>';
    $xml = '<paxml><lonetransaktioner>'.str_repeat($line, PaxmlReader::MAX_LINES + 1).'</lonetransaktioner></paxml>';

    expect(fn () => (new PaxmlReader)->read($xml))->toThrow(UnreadablePayrollFile::class, 'fler än');
});

it('reads the CSV layout with decimal commas and an optional date range', function () {
    $file = (new PayrollFileCsv)->read(paxmlFixture('testkrogen.csv'));

    expect($file->format)->toBe('CSV')
        ->and($file->lines)->toHaveCount(3)
        ->and($file->lines[0])->toEqual(new PayrollFileLine(1, PayrollFileLine::CSV, '1', null, 'OB1', 'OB-tillägg kväll', '2026-10-05', '2026-10-05', '4.5', '27.50', null))
        ->and($file->lines[1]->personalNumber)->toBe('19850302-4567')
        ->and($file->lines[1]->amount)->toBe('600.00')
        ->and([$file->lines[2]->dateFrom, $file->lines[2]->dateTo])->toBe(['2026-10-01', '2026-10-31']);
});

it('needs a column that identifies the employee in a CSV file', function () {
    (new PayrollFileCsv)->read("loneart;antal\nOB1;2\n");
})->throws(UnreadablePayrollFile::class, 'anstallningsnummer eller personnummer');

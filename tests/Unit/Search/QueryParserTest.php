<?php

declare(strict_types=1);

use App\Support\Search\QueryParser;
use App\Support\Search\SearchQuery;
use App\Support\Search\TextScore;
use Carbon\CarbonImmutable;

function parse(string $text): SearchQuery
{
    return (new QueryParser(CarbonImmutable::parse('2026-11-01')))->parse($text);
}

it('reads a type word at the start', function (string $typed, array $types, string $text) {
    $query = parse($typed);

    expect($query->types)->toBe($types)->and($query->text)->toBe($text);
})->with([
    ['faktura 1029', ['invoices'], '1029'],
    ['lev Elverket', ['suppliers', 'supplier-invoices'], 'Elverket'],
    ['konto 1930', ['accounts'], '1930'],
    ['Kund Fiskaren AB', ['customers'], 'Fiskaren AB'],
    ['projekt ombyggnad', ['projects'], 'ombyggnad'],
    ['Elverket faktura', [], 'Elverket faktura'],
]);

it('reads numbers, voucher references and implied amounts', function () {
    expect(parse('A47'))->series->toBe('A')->number->toBe(47)
        ->and(parse('a 47'))->series->toBe('A')->number->toBe(47)
        ->and(parse('1029'))->number->toBe(1029)->impliedAmount->toBe(102900)
        ->and(parse('8 750'))->number->toBeNull()->impliedAmount->toBe(875000);
});

it('reads amounts in Swedish notation as filters', function (string $typed, ?int $min, ?int $max) {
    $query = parse($typed);

    expect($query->amountMin)->toBe($min)
        ->and($query->amountMax)->toBe($max)
        ->and($query->chips[0]['kind'])->toBe('amount');
})->with([
    ['8 750,00', 875000, 875000],
    ['8750 kr', 875000, 875000],
    ['1 234,5 kr', 123450, 123450],
    ['> 10000 kr', 1000001, null],
    ['>=10000', 1000000, null],
    ['över 5 000', 500001, null],
    ['< 500 kr', null, 49999],
    ['1000-2000 kr', 100000, 200000],
]);

it('reads dates and months', function (string $typed, string $from, string $to) {
    $query = parse($typed);

    expect($query->dateFrom?->toDateString())->toBe($from)
        ->and($query->dateTo?->toDateString())->toBe($to)
        ->and($query->text)->toBe('');
})->with([
    ['2026-10-14', '2026-10-14', '2026-10-14'],
    ['14 okt', '2026-10-14', '2026-10-14'],
    ['14 oktober 2025', '2025-10-14', '2025-10-14'],
    ['14/10', '2026-10-14', '2026-10-14'],
    ['oktober', '2026-10-01', '2026-10-31'],
    // A month that has not begun this year is last year's.
    ['december', '2025-12-01', '2025-12-31'],
    ['feb 2026', '2026-02-01', '2026-02-28'],
]);

it('reads a status and keeps the rest as text', function () {
    $query = parse('Fiskaren obetalda');

    expect($query->status)->toBe(SearchQuery::UNPAID)
        ->and($query->text)->toBe('Fiskaren')
        ->and($query->chips)->toBe([['kind' => 'status', 'label' => 'Obetalda', 'token' => 'obetalda']]);

    expect(parse('ej betald')->status)->toBe(SearchQuery::UNPAID)
        ->and(parse('förfallna')->status)->toBe(SearchQuery::OVERDUE)
        ->and(parse('att attestera')->status)->toBe(SearchQuery::TO_APPROVE);
});

it('combines filters, each with the words it came from', function () {
    $query = parse('faktura Elverket > 10000 kr oktober obetald');

    expect($query->types)->toBe(['invoices'])
        ->and($query->text)->toBe('Elverket')
        ->and($query->status)->toBe(SearchQuery::UNPAID)
        ->and($query->amountMin)->toBe(1000001)
        ->and($query->dateFrom?->toDateString())->toBe('2026-10-01')
        ->and(array_column($query->chips, 'token'))->toBe(['faktura', 'obetald', '> 10000 kr', 'oktober']);
});

it('gives pages the type word when it stands alone', function () {
    expect(parse('faktura')->navigationText())->toBe('faktura')
        ->and(parse('faktura 1029')->navigationText())->toBe('')
        ->and(parse('moms')->navigationText())->toBe('moms');
});

it('scores exact, prefix, word, contains, keyword and typo in that order', function () {
    expect(TextScore::score('Moms', 'moms'))->toBe(800.0)
        ->and(TextScore::score('Momsdeklaration', 'moms'))->toBeGreaterThan(600.0)
        ->and(TextScore::score('EU-rapporter moms', 'moms'))->toBeGreaterThanOrEqual(500.0)
        ->and(TextScore::score('Ny leverantörsfaktura', 'ny lev'))->toBeGreaterThanOrEqual(450.0)
        ->and(TextScore::score('Rapporter', 'saldobalans', ['saldobalans']))->toBe(300.0)
        ->and(TextScore::score('Verifikationer', 'verifikatoner'))->toBeGreaterThan(100.0)
        ->and(TextScore::score('Kunder', 'xyz'))->toBeNull();
});

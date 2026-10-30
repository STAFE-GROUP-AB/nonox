<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Domain\Tax\WithholdingTax;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Support\Tax\MissingTaxTableException;
use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/*
 * Golden tests against Skatteverket's own 2026 monthly table 33
 * (allmanna-tabeller-manad.txt, tests/Fixtures/payroll). Every expected
 * amount below is copied from a line of that file.
 */

function table33(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/payroll/skattetabell-2026-manad-tabell-33.txt'));
}

/** Test rules only; not verified real values. */
function taxTestRules(): RuleSet
{
    foreach (['tax.rounding' => ['mode' => 'DOWN_TO_KRONA'], 'sink.rate' => ['bp' => 2250]] as $key => $value) {
        PayrollRuleVersion::create(['key' => $key, 'valid_from' => '2026-01-01', 'value' => $value, 'source_url' => 'https://example.test'])
            ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
    }

    return RuleSet::for(CarbonImmutable::parse('2026-04-25'));
}

beforeEach(function () {
    $this->tax = app(WithholdingTax::class);
});

it('imports the official file through the command and refuses a second import of the year', function () {
    $path = base_path('tests/Fixtures/payroll/skattetabell-2026-manad-tabell-33.txt');

    expect(Artisan::call('payroll:import-tax-tables', ['year' => 2026, 'file' => $path]))->toBe(0)
        ->and(Artisan::output())->toContain('1 tabeller med 3414 rader')
        ->and(Artisan::call('payroll:import-tax-tables', ['year' => 2026, 'file' => $path]))->toBe(1);
});

it('refuses a file with a gap, an unknown line or a closed last interval', function () {
    $lines = explode("\n", trim(table33()));
    unset($lines[10]);

    expect(fn () => app(TaxTableImporter::class)->import(2026, 'lucka.txt', implode("\n", $lines)))->toThrow(InvalidArgumentException::class, 'gap or overlap')
        ->and(fn () => app(TaxTableImporter::class)->import(2026, 'fel.txt', "30B33 hej\n"))->toThrow(InvalidArgumentException::class, 'record layout')
        ->and(fn () => app(TaxTableImporter::class)->import(2026, 'stangd.txt', implode("\n", array_slice(explode("\n", trim(table33())), 0, 5))))->toThrow(InvalidArgumentException::class, 'last interval');
});

it('withholds the amounts in Skatteverket\'s table 33', function (int $income, int $column, int $expectedKronor) {
    app(TaxTableImporter::class)->import(2026, 'tabell-33.txt', table33());

    expect($this->tax->fromTable(2026, 33, $column, $income * 100, taxTestRules())->taxOre)->toBe($expectedKronor * 100);
})->with([
    '1 000 kr, kolumn 1' => [1_000, 1, 0],
    '25 000 kr, kolumn 1' => [25_000, 1, 4_622],
    '25 000 kr, kolumn 3' => [25_000, 3, 2_006],
    '45 000 kr, kolumn 1' => [45_000, 1, 10_052],
    '45 000 kr, kolumn 6' => [45_000, 6, 14_345],
    '81 000 kr, kolumn 1 (33 %)' => [81_000, 1, 26_730],
]);

it('explains how the tax was reached', function () {
    app(TaxTableImporter::class)->import(2026, 'tabell-33.txt', table33());

    expect($this->tax->fromTable(2026, 33, 1, 2_500_050, taxTestRules())->explanation)
        ->toMatchArray(['method' => 'table', 'table' => 33, 'column' => 1, 'income_kronor' => 25_000, 'interval' => [24_801, 25_000], 'amount_kronor' => 4_622]);
});

it('withholds SINK as a flat rate from the rules', function () {
    expect($this->tax->sink(3_000_000, taxTestRules())->taxOre)->toBe(675_000);
});

it('blocks without the table or without a verified rounding rule', function () {
    app(TaxTableImporter::class)->import(2026, 'tabell-33.txt', table33());

    expect(fn () => $this->tax->fromTable(2026, 34, 1, 2_500_000, taxTestRules()))->toThrow(MissingTaxTableException::class)
        ->and(fn () => $this->tax->fromTable(2026, 33, 1, 8_100_000, RuleSet::for(CarbonImmutable::parse('2025-04-25'))))->toThrow(MissingPayrollRuleException::class);
});

it('withholds a one-off payment from the expected annual income and column', function () {
    $rules = taxTestRules();
    PayrollRuleVersion::create(['key' => 'tax.one_off', 'valid_from' => '2026-01-01', 'value' => ['columns' => ['1' => [[0, 25041, 0], [25042, 82800, 1000], [82801, 192000, 2100], [192001, 477600, 2600], [477601, 660000, 3400], [660001, null, 5400]]]], 'source_url' => 'https://example.test'])
        ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
    $rules = RuleSet::for(CarbonImmutable::parse('2026-04-25'));

    expect($this->tax->oneOff(1_000_000, 36_000_000, 1, $rules)->taxOre)->toBe(260_000)
        ->and($this->tax->oneOff(1_000_000, 2_000_000, 1, $rules)->taxOre)->toBe(0)
        ->and(fn () => $this->tax->oneOff(1_000_000, 36_000_000, 3, $rules))->toThrow(MissingPayrollRuleException::class);
});

<?php

declare(strict_types=1);

use App\Domain\Units\MaterialConversions;
use App\Domain\Units\QuantityRoundsToZero;
use App\Domain\Units\UnitConverter;
use App\Domain\Units\UnitDefaults;
use Brick\Math\BigRational;

/*
 * Units of measure (ADR-071 §12): exact fractions, milli-units of the stock
 * unit, half up once, zero refused, more than 0,5 % warns. The cases are
 * the fixtures of §12.6 in tests/Fixtures/units.
 */

function unitFixtures(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/units/units-of-measure.json'), true, flags: JSON_THROW_ON_ERROR);
}

dataset('unit conversions', collect(unitFixtures()['conversions'])->mapWithKeys(fn (array $case): array => [$case['id'].' '.$case['note'] => [$case]])->all());

it('converts the fixture cases as §12.6 says', function (array $case) {
    $manual = array_map(fn (string $fraction): BigRational => BigRational::of($fraction), $case['manual'] ?? []);
    $conversions = [...MaterialConversions::for($case['stock'], $case['length_um'], $case['width_um'], $case['thickness_um']), ...$manual];
    $perStock = UnitConverter::enteredPerStock($case['stock'], $case['unit'], $conversions);

    expect($perStock)->not->toBeNull();

    if (isset($case['fraction'])) {
        expect((string) $perStock)->toBe($case['fraction']);
    }

    if ($case['refused'] ?? false) {
        expect(fn () => UnitConverter::toStock($case['entered'], $perStock))->toThrow(QuantityRoundsToZero::class);

        return;
    }

    $result = UnitConverter::toStock($case['entered'], $perStock);

    expect($result->stockMilli)->toBe($case['stock_milli'])
        ->and($result->warns)->toBe($case['warns']);

    if (isset($case['change_percent'])) {
        expect($result->changePercent)->toBe($case['change_percent']);
    }
})->with('unit conversions');

it('keeps a line value exact and the unit cost half up, as the fixtures say', function (array $case) {
    expect(UnitConverter::lineValueOre($case['quantity'], $case['price_ore']))->toBe($case['value_ore']);

    if (isset($case['unit_cost_ore'])) {
        expect(UnitConverter::unitCostOre($case['value_ore'], $case['stock_milli']))->toBe($case['unit_cost_ore']);
    }
})->with(collect(unitFixtures()['values'])->mapWithKeys(fn (array $case): array => [$case['id'].' '.$case['note'] => [$case]])->all());

it('shows why the line value is kept: quantity times the rounded unit cost would be 145 öre off (F-U1c)', function () {
    expect(UnitConverter::lineValueOre('584.795', 2779))->toBe(1_625_145);
});

it('derives the conversions of §12.3 from the material size', function () {
    $stud = MaterialConversions::for('lpm', 4_800_000, 95_000, 45_000);

    expect((string) $stud['m³'])->toBe('171/40000')
        ->and((string) $stud['m²'])->toBe('19/200')
        ->and((string) $stud['st'])->toBe('5/24')
        ->and($stud)->not->toHaveKey('m');

    $piece = MaterialConversions::for('st', 4_800_000, 95_000, 45_000);

    expect((string) $piece['m³'])->toBe('513/25000')
        ->and((string) $piece['lpm'])->toBe('24/5')
        ->and((string) $piece['m²'])->toBe('57/125');

    $board = MaterialConversions::for('m²', 2_440_000, 1_220_000, 27_000);

    expect((string) $board['m³'])->toBe('27/1000')
        ->and($board)->toHaveKey('st');

    expect(MaterialConversions::for('lpm', null, null, null))->toBe([])
        ->and(MaterialConversions::for('lpm', null, 95_000, null))->toHaveKey('m²')
        ->and(MaterialConversions::for('lpm', null, 95_000, null))->not->toHaveKey('m³')
        ->and(MaterialConversions::for('kg', 1, 1, 1))->toBe([]);
});

it('converts within a dimension without any article conversion', function (string $stock, string $entered, string $fraction) {
    expect((string) UnitConverter::enteredPerStock($stock, $entered))->toBe($fraction);
})->with([
    'kg to g' => ['kg', 'g', '1000'],
    'g to kg' => ['g', 'kg', '1/1000'],
    'l to ml' => ['l', 'ml', '1000'],
    'm3 to l' => ['m³', 'l', '1000'],
    'l to m3' => ['l', 'm³', '1/1000'],
    'm to mm' => ['m', 'mm', '1000'],
    'lpm to m' => ['lpm', 'm', '1'],
    'm2 to dm2' => ['m²', 'dm²', '100'],
    'h to min' => ['h', 'min', '60'],
    'an alias' => ['kvm', 'dm2', '100'],
]);

it('has no conversion between units of different dimensions or without a fixed size', function () {
    expect(UnitConverter::enteredPerStock('lpm', 'm³'))->toBeNull()
        ->and(UnitConverter::enteredPerStock('st', 'förp'))->toBeNull()
        ->and(UnitConverter::enteredPerStock('kg', 'l'))->toBeNull()
        ->and(UnitConverter::enteredPerStock('st', 'rulle'))->toBeNull();
});

it('lets an article conversion win over the built-in one, and the same unit is one to one', function () {
    $own = ['g' => BigRational::of('999')];

    expect((string) UnitConverter::enteredPerStock('kg', 'g', $own))->toBe('999')
        ->and((string) UnitConverter::enteredPerStock('kg', 'KG'))->toBe('1');
});

it('rounds half up once at entry and refuses nothing at exactly half a milli', function () {
    expect(UnitConverter::toStock('0.0005', BigRational::one())->stockMilli)->toBe(1)
        ->and(UnitConverter::toStock('1.2345', BigRational::one())->stockMilli)->toBe(1235)
        ->and(fn () => UnitConverter::toStock('0.0004', BigRational::one()))->toThrow(QuantityRoundsToZero::class)
        ->and(fn () => UnitConverter::toStock('0', BigRational::one()))->toThrow(QuantityRoundsToZero::class);
});

it('warns above 0,5 % and not at or below it', function () {
    // 1 kg = 1 000 g, so a typed number of grams is the stock quantity in milli-kg, rounded half up.
    $gramsPerKg = BigRational::of(1000);

    expect(UnitConverter::toStock('20.5', $gramsPerKg)->warns)->toBeTrue()
        ->and(UnitConverter::toStock('100.5', $gramsPerKg)->warns)->toBeFalse()
        ->and(UnitConverter::toStock('3', $gramsPerKg)->warns)->toBeFalse()
        ->and(UnitConverter::toStock('1000.4', $gramsPerKg)->warns)->toBeFalse();
});

it('knows the default catalogue: codes, aliases, stock choices and the helper units', function () {
    expect(UnitDefaults::codeFor('Styck'))->toBe('st')
        ->and(UnitDefaults::codeFor(' KVM '))->toBe('m²')
        ->and(UnitDefaults::codeFor('rulle'))->toBeNull()
        ->and(UnitDefaults::stockChoices())->toContain('st', 'lpm', 'm²', 'm³', 'kg')
        ->and(UnitDefaults::stockChoices())->not->toContain('mm', 'cl', 'dm²')
        ->and(UnitDefaults::ALL['lpm'][4])->toBe('MTR')
        ->and(UnitDefaults::ALL['förp'][4])->toBe('XPK');
});

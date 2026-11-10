<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Units\SaveUnit;
use App\Actions\Units\SetUnitArchived;
use App\Domain\Units\QuantityOutOfRange;
use App\Domain\Units\UnitCatalogue;
use App\Domain\Units\UnitConverter;
use App\Domain\Units\UnitDefaults;
use App\Livewire\Invoicing\Articles;
use App\Livewire\Invoicing\Units as UnitsPage;
use App\Models\Account;
use App\Models\Article;
use App\Models\Unit;
use App\Modules\Inventory\Actions\UnitConversions;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Inventory\Structures\StructureWriter;
use App\Modules\Kitchen\Actions\SaveRecipe;
use App\Support\Peppol\UblInvoiceWriter;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigRational;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Kitchen;

/*
 * Units of measure on articles (ADR-071 §12): the catalogue, the stock,
 * purchase and sales unit, the material's size, conversions, and what is
 * refused. The numbers are the fixtures of §12.6 (tests/Fixtures/units).
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    UnitCatalogue::seed((int) $this->company->id);
    $this->save = app(SaveArticle::class);
});

function unitId(string $code): int
{
    return (int) Unit::query()->where('code', $code)->value('id');
}

function studInput(array $extra = []): array
{
    return [
        'number' => 'REG-4595', 'name' => 'Reglar 45x95', 'price' => '60', 'vat_rate' => 2500,
        'stock_unit_id' => unitId('lpm'), 'purchase_unit_id' => unitId('m³'), 'sales_unit_id' => unitId('lpm'),
        'material_length' => '4800', 'material_width' => '95', 'material_thickness' => '45',
        ...$extra,
    ];
}

it('seeds the default catalogue once, with codes from the Peppol map', function () {
    UnitCatalogue::seed((int) $this->company->id);
    UnitCatalogue::seed((int) $this->company->id);

    $units = Unit::query()->get()->keyBy('code');

    expect($units)->toHaveCount(count(UnitDefaults::ALL))
        ->and($units['lpm'])->rec20_code->toBe('MTR')->dimension->value->toBe('LENGTH')
        ->and($units['m³'])->rec20_code->toBe('MTQ')->aliases->toContain('m3')
        ->and($units['förp'])->base_numerator->toBeNull()
        ->and($units['g'])->base_numerator->toBe(1)->base_denominator->toBe(1000)
        ->and($units['mm']->is_default)->toBeTrue();

    foreach (['st', 'm', 'm²', 'm³', 'kg', 'g', 'l', 'h', 'förp', 'par', 'set', 'mm', 'cm', 'min'] as $code) {
        expect($units[$code]->rec20_code)->toBe(UblInvoiceWriter::unitCode($code));
    }
});

it('turns the unit texts of existing articles into catalogue units (F-U7)', function () {
    foreach (json_decode((string) file_get_contents(base_path('tests/Fixtures/units/units-of-measure.json')), true)['migration'] as $case) {
        $unit = Unit::query()->findOrFail(UnitCatalogue::resolveId((int) $this->company->id, $case['text']));

        expect($unit->code)->toBe($case['code']);

        if (isset($case['dimension'])) {
            expect($unit)->dimension->value->toBe($case['dimension'])->rec20_code->toBe($case['rec20'])->is_default->toBeFalse();
        }
    }

    expect(UnitCatalogue::resolveId((int) $this->company->id, 'Rulle'))->toBe(unitId('rulle'))
        ->and(Unit::query()->where('code', 'rulle')->count())->toBe(1);
});

it('gives every article a stock unit, also when it is written without units', function () {
    $article = Article::create(['number' => '1', 'name' => 'Tim', 'unit' => 'tim', 'price_ore' => 100, 'vat_rate' => 2500, 'account_id' => Account::query()->where('number', 3001)->value('id')]);

    expect($article->fresh()->stock_unit_id)->toBe(unitId('h'));
});

it('keeps an article saved with only a unit text working: the text as typed, all three units following it', function () {
    $article = ($this->save)($this->owner, $this->company, ['number' => '1', 'name' => 'Skruv', 'unit' => 'Styck', 'price' => '2', 'vat_rate' => 2500]);

    expect($article->fresh())
        ->unit->toBe('Styck')
        ->stock_unit_id->toBe(unitId('st'))
        ->purchase_unit_id->toBe(unitId('st'))
        ->sales_unit_id->toBe(unitId('st'));

    $article = ($this->save)($this->owner, $this->company, ['number' => '1', 'name' => 'Skruv', 'unit' => 'kvm', 'price' => '2', 'vat_rate' => 2500], $article);

    expect($article->fresh())->unit->toBe('kvm')->stock_unit_id->toBe(unitId('m²'))->sales_unit_id->toBe(unitId('m²'));
});

it('saves stock, purchase and sales unit with the size, and derives the conversions (F-U1)', function () {
    $article = ($this->save)($this->owner, $this->company, studInput())->fresh();

    expect($article)
        ->unit->toBe('lpm')
        ->stock_unit_id->toBe(unitId('lpm'))
        ->purchase_unit_id->toBe(unitId('m³'))
        ->material_length_um->toBe(4_800_000)
        ->material_width_um->toBe(95_000)
        ->material_thickness_um->toBe(45_000);

    $rows = ArticleUnitConversion::query()->where('article_id', $article->id)->get()->keyBy('unit');

    expect($rows->keys()->sort()->values()->all())->toBe(['m²', 'm³', 'st'])
        ->and($rows['m³'])->numerator->toBe(171)->denominator->toBe(40_000)->source->toBe('DIMENSIONS')
        ->and($rows['st'])->numerator->toBe(5)->denominator->toBe(24);
});

it('recomputes the derived conversions when the size changes, and drops them when it is cleared', function () {
    $article = ($this->save)($this->owner, $this->company, studInput());
    ($this->save)($this->owner, $this->company, studInput(['material_thickness' => '50']), $article);

    expect(ArticleUnitConversion::query()->where('article_id', $article->id)->where('unit', 'm³')->sole())->numerator->toBe(19)->denominator->toBe(4_000);

    ($this->save)($this->owner, $this->company, studInput(['material_length' => '', 'material_width' => '', 'material_thickness' => '', 'purchase_unit_id' => null]), $article);

    expect(ArticleUnitConversion::query()->where('article_id', $article->id)->count())->toBe(0);
});

it('refuses a purchase or sales unit that has no way to convert to the stock unit', function () {
    expect(fn () => ($this->save)($this->owner, $this->company, studInput(['material_width' => '', 'material_thickness' => '', 'material_length' => ''])))
        ->toThrow(ValidationException::class, 'Det saknas en omräkning mellan lpm och m³');

    expect(Article::query()->count())->toBe(0);
});

it('accepts a conversion entered by hand, and lets it win over a derived one (F-U5)', function () {
    $screw = ($this->save)($this->owner, $this->company, [
        'number' => 'SKR', 'name' => 'Skruv 4x40', 'price' => '2', 'vat_rate' => 2500,
        'stock_unit_id' => unitId('st'), 'purchase_unit_id' => unitId('förp'), 'sales_unit_id' => unitId('st'),
        'conversions' => [['unit' => 'förp', 'per' => '0,005']],
    ]);

    $row = ArticleUnitConversion::query()->where('article_id', $screw->id)->sole();

    expect($row)->unit->toBe('förp')->source->toBe('MANUAL')->numerator->toBe(1)->denominator->toBe(200);

    $stud = ($this->save)($this->owner, $this->company, studInput(['conversions' => [['unit' => 'm³', 'per' => '0,0043']]]));

    expect(ArticleUnitConversion::query()->where('article_id', $stud->id)->where('unit', 'm³')->sole())
        ->source->toBe('MANUAL')->numerator->toBe(43)->denominator->toBe(10_000);

    ($this->save)($this->owner, $this->company, studInput(['conversions' => []]), $stud);

    expect(ArticleUnitConversion::query()->where('article_id', $stud->id)->where('unit', 'm³')->sole())->source->toBe('DIMENSIONS');
});

it('refuses conversions that make no sense', function (array $row, string $message) {
    expect(fn () => ($this->save)($this->owner, $this->company, [
        'number' => 'X', 'name' => 'X', 'unit' => 'kg', 'price' => '1', 'vat_rate' => 2500,
        'conversions' => [$row],
    ]))->toThrow(ValidationException::class, $message);
})->with([
    'a built-in unit' => [['unit' => 'g', 'per' => '1000'], 'räknas redan om'],
    'the stock unit itself' => [['unit' => 'kg', 'per' => '1'], 'räknas redan om'],
    'zero' => [['unit' => 'förp', 'per' => '0'], 'Mer än noll'],
    'not a number' => [['unit' => 'förp', 'per' => 'många'], 'Mer än noll'],
    'ten decimals' => [['unit' => 'förp', 'per' => '0,1234567891'], 'högst nio siffror'],
    'too many digits' => [['unit' => 'förp', 'per' => '99999999999999999999'], 'högst nio siffror'],
    'ten integer digits' => [['unit' => 'förp', 'per' => '1234567890'], 'högst nio siffror'],
    'no unit' => [['unit' => '', 'per' => '5'], 'Välj enheten'],
]);

it('reads the size in millimetres with up to three decimals', function () {
    $veneer = ($this->save)($this->owner, $this->company, [
        'number' => 'FANER', 'name' => 'Ekfaner', 'price' => '90', 'vat_rate' => 2500,
        'stock_unit_id' => unitId('m²'), 'material_thickness' => '0,6',
    ]);

    expect($veneer->fresh()->material_thickness_um)->toBe(600);

    foreach (['1,2345' => 'högst tre decimaler', 'tjock' => 'högst tre decimaler', '0' => 'mer än noll', '100001' => 'högst 100 000'] as $text => $message) {
        expect(fn () => ($this->save)($this->owner, $this->company, ['number' => 'F2', 'name' => 'F2', 'price' => '1', 'vat_rate' => 2500, 'stock_unit_id' => unitId('m²'), 'material_width' => $text]))
            ->toThrow(ValidationException::class, $message);
    }
});

it('accepts the units by code, for imports and the demo', function () {
    app(SaveUnit::class)($this->owner, $this->company, ['code' => 'rulle', 'name' => 'Rulle', 'dimension' => 'OTHER']);
    $article = ($this->save)($this->owner, $this->company, ['number' => 'K', 'name' => 'Kabel', 'price' => '9', 'vat_rate' => 2500, 'stock_unit' => 'm', 'purchase_unit' => 'rulle', 'sales_unit' => 'M', 'conversions' => [['unit' => 'rulle', 'per' => '0,02']]]);
    expect($article->fresh())->stock_unit_id->toBe(unitId('m'))->sales_unit_id->toBe(unitId('m'))->unit->toBe('m')
        ->and(fn () => ($this->save)($this->owner, $this->company, ['number' => 'K2', 'name' => 'K2', 'price' => '9', 'vat_rate' => 2500, 'stock_unit' => 'okänd']))
        ->toThrow(ValidationException::class, 'finns inte i enhetslistan');
});

it('refuses a unit of another company, an archived unit and a missing stock unit', function () {
    [$other, $otherCompany] = Bookkeeping::company();
    $foreign = UnitCatalogue::resolveId((int) $otherCompany->id, 'm');

    expect(fn () => ($this->save)($this->owner, $this->company, ['number' => 'A', 'name' => 'A', 'price' => '1', 'vat_rate' => 2500, 'stock_unit_id' => $foreign]))
        ->toThrow(ValidationException::class, 'Enheten finns inte');

    app(SetUnitArchived::class)($this->owner, $this->company, unitId('par'), true);

    expect(fn () => ($this->save)($this->owner, $this->company, ['number' => 'B', 'name' => 'B', 'price' => '1', 'vat_rate' => 2500, 'stock_unit_id' => unitId('par')]))
        ->toThrow(ValidationException::class, 'är arkiverad')
        ->and(fn () => ($this->save)($this->owner, $this->company, ['number' => 'C', 'name' => 'C', 'price' => '1', 'vat_rate' => 2500, 'stock_unit_id' => null]))
        ->toThrow(ValidationException::class, 'Välj lagerenhet');
});

it('keeps an archived unit on the article that already uses it', function () {
    $article = ($this->save)($this->owner, $this->company, ['number' => 'P', 'name' => 'Handskar', 'price' => '50', 'vat_rate' => 2500, 'stock_unit_id' => unitId('par')]);
    app(SetUnitArchived::class)($this->owner, $this->company, unitId('par'), true);

    $saved = ($this->save)($this->owner, $this->company, ['number' => 'P', 'name' => 'Handskar 2', 'price' => '50', 'vat_rate' => 2500, 'stock_unit_id' => unitId('par')], $article);

    expect($saved->fresh())->name->toBe('Handskar 2')->stock_unit_id->toBe(unitId('par'));
});

it('refuses to change the stock unit once there are stock movements or recipes', function () {
    Inventory::enable($this->company, $this->owner);
    $places = Inventory::locations($this->company, $this->owner);
    $article = Inventory::article($this->company, $this->owner);
    Inventory::receive($this->company, $this->owner, $article, $places['bin1'], '1', '80');

    expect(fn () => ($this->save)($this->owner, $this->company, ['number' => $article->number, 'name' => $article->name, 'price' => '199', 'vat_rate' => 2500, 'stock_unit_id' => unitId('kg')], $article->fresh()))
        ->toThrow(ValidationException::class, 'Lagerenheten kan inte ändras');

    $fresh = ($this->save)($this->owner, $this->company, ['number' => $article->number, 'name' => 'Kaffekopp 2', 'price' => '199', 'vat_rate' => 2500, 'stock_unit_id' => unitId('st')], $article->fresh());

    expect($fresh->fresh())->name->toBe('Kaffekopp 2');
});

it('writes the sales unit as the article unit, for invoices and Peppol (F-U8)', function () {
    $article = ($this->save)($this->owner, $this->company, studInput(['purchase_unit_id' => null, 'material_width' => '', 'material_thickness' => '', 'material_length' => '']))->fresh();

    expect($article->unit)->toBe('lpm')
        ->and(Unit::query()->where('code', 'lpm')->sole()->rec20_code)->toBe('MTR');

    // Today's Peppol map has no running metre; it goes out as C62 until the code is verified (§12.5, legal-verify).
    expect(UblInvoiceWriter::unitCode($article->unit))->toBe('C62');
});

it('stores a bill-of-materials row in the stock unit and keeps what was typed (F-U1)', function () {
    Inventory::enable($this->company, $this->owner);
    $stud = ($this->save)($this->owner, $this->company, studInput());
    $table = ($this->save)($this->owner, $this->company, ['number' => 'BORD', 'name' => 'Bord', 'unit' => 'st', 'price' => '4000', 'vat_rate' => 2500]);
    $writer = app(StructureWriter::class);
    $base = ['name' => 'Bord', 'yield' => '1', 'yield_unit' => BillOfMaterials::UNIT];

    $structure = $writer->save($this->company, $this->owner, StructureKind::Recipe, $table, [...$base, 'rows' => [
        ['article_id' => $stud->id, 'quantity' => '2,5', 'unit' => 'm³'],
        ['article_id' => $stud->id, 'quantity' => '4', 'unit' => 'st'],
        ['article_id' => $stud->id, 'quantity' => '3', 'unit' => 'lpm'],
    ]]);

    $rows = BomRow::query()->where('bill_of_materials_id', $structure->id)->orderBy('position')->get();

    expect($rows->pluck('quantity_milli')->all())->toBe([584_795, 19_200, 3_000])
        ->and($rows[0])->entered_quantity->toBe('2.5')->entered_unit->toBe('m³')
        ->and($rows[2])->entered_quantity->toBeNull()->entered_unit->toBeNull()
        ->and($writer->warnings)->toBe([]);
});

it('refuses a row that is zero in the stock unit and warns about a coarse round trip (F-U6)', function () {
    Inventory::enable($this->company, $this->owner);
    $flour = ($this->save)($this->owner, $this->company, ['number' => 'MJO', 'name' => 'Mjöl', 'unit' => 'kg', 'price' => '20', 'vat_rate' => 1200]);
    $cake = ($this->save)($this->owner, $this->company, ['number' => 'TAR', 'name' => 'Tårta', 'unit' => 'st', 'price' => '300', 'vat_rate' => 1200]);
    $writer = app(StructureWriter::class);
    $base = ['name' => 'Tårta', 'yield' => '1', 'yield_unit' => BillOfMaterials::UNIT];

    $writer->save($this->company, $this->owner, StructureKind::Recipe, $cake, [...$base, 'valid_from' => '2026-01-01', 'rows' => [['article_id' => $flour->id, 'quantity' => '1,5', 'unit' => 'g']]]);

    expect($writer->warnings)->toHaveCount(1)
        ->and($writer->warnings[0])->toContain('Mjöl')->toContain('33,33');

    expect(fn () => $writer->save($this->company, $this->owner, StructureKind::Recipe, $cake, [...$base, 'valid_from' => '2026-02-01', 'rows' => [['article_id' => $flour->id, 'quantity' => '0,4', 'unit' => 'g']]]))
        ->toThrow(ValidationException::class, 'Mängden blir noll i lagerenheten');
});

it('converts unit conversions entered one at a time, with exact fractions (the Omräkningar panel action)', function () {
    UnitCatalogue::resolve((int) $this->company->id, 'fl');
    $bottle = ($this->save)($this->owner, $this->company, ['number' => 'GIN', 'name' => 'Gin', 'unit' => 'fl', 'price' => '300', 'vat_rate' => 2500]);
    Inventory::enable($this->company, $this->owner);
    $actions = app(UnitConversions::class);

    $conversion = $actions->save($this->owner, $this->company, $bottle, 'cl', '70');

    expect($conversion)->numerator->toBe(70)->denominator->toBe(1)->factor_milli->toBe(70_000)->source->toBe('MANUAL');

    $actions->save($this->owner, $this->company, $bottle, 'cl', '0,333333333');

    expect(ArticleUnitConversion::query()->where('article_id', $bottle->id)->sole())->numerator->toBe(333_333_333)->denominator->toBe(1_000_000_000);

    foreach (['0', '-3', 'abc', '1,0000000001'] as $bad) {
        expect(fn () => $actions->save($this->owner, $this->company, $bottle, 'cl', $bad))->toThrow(ValidationException::class);
    }
});

it('puts back the derived conversion when a manual one is removed', function () {
    Inventory::enable($this->company, $this->owner);
    $stud = ($this->save)($this->owner, $this->company, studInput(['conversions' => [['unit' => 'm³', 'per' => '0,0043']]]));
    $manual = ArticleUnitConversion::query()->where('article_id', $stud->id)->where('unit', 'm³')->sole();

    app(UnitConversions::class)->delete($this->owner, $this->company, $manual->id);

    expect(ArticleUnitConversion::query()->where('article_id', $stud->id)->where('unit', 'm³')->sole())->source->toBe('DIMENSIONS');
});

it('adds, renames, archives and restores units of the company', function () {
    $save = app(SaveUnit::class);
    $roll = $save($this->owner, $this->company, ['code' => 'rulle', 'name' => 'Rulle', 'dimension' => 'OTHER', 'rec20_code' => 'C62']);

    expect($roll)->is_default->toBeFalse()->rec20_code->toBe('C62')->base_numerator->toBeNull();

    $renamed = $save($this->owner, $this->company, ['code' => 'ändras ej', 'name' => 'Tapetrulle', 'dimension' => 'MASS', 'rec20_code' => ''], $roll);

    expect($renamed)->code->toBe('rulle')->name->toBe('Tapetrulle')->rec20_code->toBeNull()->dimension->value->toBe('OTHER');

    app(SetUnitArchived::class)($this->owner, $this->company, $roll->id, true);
    expect($roll->fresh()->isArchived())->toBeTrue();

    app(SetUnitArchived::class)($this->owner, $this->company, $roll->id, false);
    expect($roll->fresh()->isArchived())->toBeFalse();
});

it('refuses a unit that exists, by code or alias, and a bad code or Peppol code', function (array $input, string $message) {
    expect(fn () => app(SaveUnit::class)($this->owner, $this->company, ['name' => 'X', 'dimension' => 'OTHER', ...$input]))
        ->toThrow(ValidationException::class, $message);
})->with([
    'same code' => [['code' => 'm²'], 'finns redan'],
    'other case' => [['code' => 'LPM'], 'finns redan'],
    'an alias' => [['code' => 'kvm'], 'finns redan'],
    'a space' => [['code' => 'två ord'], 'mellanslag'],
    'too long' => [['code' => 'abcdefghijk'], 'enhet'],
    'a Peppol code not in the list' => [['code' => 'bunt', 'rec20_code' => 'ZZZ'], 'Peppol-kod'],
    'no dimension' => [['code' => 'bunt', 'dimension' => 'FAKE'], 'mått'],
]);

it('lets those who write invoices manage units, and nobody else', function (string $role, bool $allowed) {
    $member = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
    $this->company->unsetRelation('users');

    $attempt = fn () => app(SaveUnit::class)($member, $this->company, ['code' => 'bunt', 'name' => 'Bunt', 'dimension' => 'OTHER']);

    $allowed ? expect($attempt()->code)->toBe('bunt') : expect($attempt)->toThrow(AuthorizationException::class);
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'accountant' => ['accountant', true],
    'bookkeeper' => ['bookkeeper', true],
    'approver' => ['approver', false],
    'auditor' => ['auditor', false],
]);

it('lets only those who write invoices archive a unit', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->company->unsetRelation('users');
    UnitCatalogue::seed((int) $this->company->id);

    expect(fn () => app(SetUnitArchived::class)($auditor, $this->company, unitId('par'), true))->toThrow(AuthorizationException::class);
});

it('runs the Enheter page: add, edit, archive, restore and hide archived ones', function () {
    Livewire::test(UnitsPage::class)
        ->assertSee('styck')
        ->call('create')
        ->set('form.code', 'bunt')->set('form.name', 'Bunt')->set('form.dimension', 'OTHER')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Enheten bunt är tillagd.')
        ->call('edit', unitId('bunt'))
        ->set('form.name', 'Bunt trä')
        ->call('save')
        ->assertDispatched('toast', message: 'Enheten bunt är sparad.')
        ->call('archive', unitId('bunt'))
        ->assertDispatched('toast', message: 'Enheten bunt är arkiverad och föreslås inte längre.')
        ->assertDontSee('Bunt trä')
        ->set('showArchived', true)
        ->assertSee('Bunt trä')
        ->call('restore', unitId('bunt'))
        ->assertDispatched('toast', message: 'Enheten bunt används igen.');

    Livewire::test(UnitsPage::class)->call('create')->set('form.code', 'st')->set('form.name', 'Dubblett')->call('save')->assertHasErrors('code');
});

it('keeps the Enheter page to the viewer and the manager of the company', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->company->unsetRelation('users');

    $this->actingAs($auditor);
    Livewire::test(UnitsPage::class)->assertDontSee('Ny enhet');
    $this->get(route('invoicing.units'))->assertOk();

    $roll = app(SaveUnit::class)($this->owner, $this->company, ['code' => 'bunt', 'name' => 'Bunt', 'dimension' => 'OTHER']);

    [$stranger, $strangersCompany] = Bookkeeping::company();
    $this->actingAs($stranger);
    CurrentCompany::set($strangersCompany);

    Livewire::test(UnitsPage::class)->call('edit', $roll->id)->assertSet('showForm', false)->assertSet('form.name', '');

    expect(Unit::query()->whereKey($roll->id)->exists())->toBeFalse();
});

it('saves units, size and conversions from the article form', function () {
    Inventory::enable($this->company, $this->owner);

    Livewire::test(Articles::class)
        ->call('create')
        ->set('form.number', 'REG-1')->set('form.name', 'Regel')->set('form.price', '60')->set('form.unit', 'lpm')
        ->call('save')
        ->assertHasNoErrors();

    $article = Article::query()->where('number', 'REG-1')->sole();

    expect($article)->stock_unit_id->toBe(unitId('lpm'))->sales_unit_id->toBe(unitId('lpm'));

    Livewire::test(Articles::class)
        ->call('edit', $article->id)
        ->set('tab', 'avancerad')
        ->assertSet('form.stock_unit_id', (string) unitId('lpm'))
        ->set('form.purchase_unit_id', (string) unitId('m³'))
        ->set('form.material_width', '95')->set('form.material_thickness', '45')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect($article->fresh())->purchase_unit_id->toBe(unitId('m³'))
        ->and(ArticleUnitConversion::query()->where('article_id', $article->id)->where('unit', 'm³')->sole())->numerator->toBe(171);

    Livewire::test(Articles::class)
        ->call('edit', $article->id)
        ->set('tab', 'avancerad')
        ->assertSet('form.material_thickness', '45')
        ->assertSee('från måtten')
        ->set('form.purchase_unit_id', (string) unitId('förp'))
        ->call('save')
        ->assertHasErrors('purchase_unit_id')
        ->call('addConversion')
        ->set('form.conversions.0.unit', 'förp')->set('form.conversions.0.per', '0,1')
        ->call('save')
        ->assertHasNoErrors();

    expect(ArticleUnitConversion::query()->where('article_id', $article->id)->where('unit', 'förp')->sole())->numerator->toBe(1)->denominator->toBe(10);
});

it('counts the same queries for a short and a long article list', function () {
    Livewire::test(Articles::class)->assertOk();

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Articles::class)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    };

    foreach (range(1, 2) as $i) {
        ($this->save)($this->owner, $this->company, studInput(['number' => "S-{$i}", 'name' => "Regel {$i}"]));
    }

    $short = $count();

    foreach (range(3, 30) as $i) {
        ($this->save)($this->owner, $this->company, studInput(['number' => "S-{$i}", 'name' => "Regel {$i}", 'sales_unit_id' => unitId($i % 2 === 0 ? 'm' : 'lpm')]));
    }

    expect($count())->toBe($short);
});

it('shows a stock unit that differs from the sales unit in the list', function () {
    ($this->save)($this->owner, $this->company, studInput(['sales_unit_id' => unitId('m²')]));

    Livewire::test(Articles::class)->assertSee('lager i lpm');
});

it('hands the round-trip warning of a recipe to the screen, and still saves the recipe', function () {
    ['owner' => $owner, 'company' => $company] = Kitchen::company();
    $this->actingAs($owner);
    $flour = app(SaveArticle::class)($owner, $company, ['number' => 'MJO', 'name' => 'Mjöl', 'unit' => 'kg', 'price' => '20', 'vat_rate' => 1200]);
    $cake = app(SaveArticle::class)($owner, $company, ['number' => 'TAR', 'name' => 'Tårta', 'unit' => 'st', 'price' => '300', 'vat_rate' => 1200]);
    $save = app(SaveRecipe::class);

    $recipe = $save($owner, $company, $cake, ['name' => 'Tårta', 'yield' => '1', 'yield_unit' => BillOfMaterials::UNIT, 'rows' => [['article_id' => $flour->id, 'quantity' => '1,5', 'unit' => 'g']]]);

    expect($recipe->rows->first()->quantity_milli)->toBe(2)
        ->and($save->warnings())->toHaveCount(1);

    $save($owner, $company, $cake, ['name' => 'Tårta', 'valid_from' => '2099-01-01', 'yield' => '1', 'yield_unit' => BillOfMaterials::UNIT, 'rows' => [['article_id' => $flour->id, 'quantity' => '2', 'unit' => 'g']]]);

    expect($save->warnings())->toBe([]);
});

it('writes the conversion row as a sentence with the stock unit, so 1 st = 0,005 förp (the screw)', function () {
    $screw = ($this->save)($this->owner, $this->company, [
        'number' => 'SKR', 'name' => 'Skruv', 'price' => '2', 'vat_rate' => 2500,
        'stock_unit' => 'st', 'purchase_unit' => 'förp', 'conversions' => [['unit' => 'förp', 'per' => '0,005']],
    ]);

    Livewire::test(Articles::class)->call('edit', $screw->id)->set('tab', 'avancerad')
        ->assertSee('1 st =')
        ->assertSet('form.conversions.0.per', '0,005')
        ->assertDontSee('placeholder="200"', false);
});

it('answers a huge number or size with a validation error, not a server error', function () {
    foreach (['99999999999999999999', '1234567890'] as $per) {
        expect(fn () => ($this->save)($this->owner, $this->company, ['number' => 'O'.$per, 'name' => 'O', 'unit' => 'kg', 'price' => '1', 'vat_rate' => 2500, 'conversions' => [['unit' => 'förp', 'per' => $per]]]))
            ->toThrow(ValidationException::class);
    }

    expect(fn () => ($this->save)($this->owner, $this->company, [
        'number' => 'BIG', 'name' => 'Stor', 'price' => '1', 'vat_rate' => 2500, 'stock_unit' => 'st',
        'material_length' => '99999,999', 'material_width' => '99999,999', 'material_thickness' => '99999,999',
    ]))->toThrow(ValidationException::class, 'Måtten är för stora');

    expect(fn () => UnitConverter::toStock('99999999999999999999', BigRational::one()))
        ->toThrow(QuantityOutOfRange::class);

    Inventory::enable($this->company, $this->owner);
    $kg = ($this->save)($this->owner, $this->company, ['number' => 'KG', 'name' => 'Kg', 'unit' => 'kg', 'price' => '1', 'vat_rate' => 2500]);
    $dish = ($this->save)($this->owner, $this->company, ['number' => 'D', 'name' => 'Rätt', 'unit' => 'st', 'price' => '1', 'vat_rate' => 2500]);

    expect(fn () => app(StructureWriter::class)->save($this->company, $this->owner, StructureKind::Recipe, $dish, ['name' => 'R', 'yield' => '1', 'yield_unit' => BillOfMaterials::UNIT, 'rows' => [['article_id' => $kg->id, 'quantity' => '99999999999999999999']]]))
        ->toThrow(ValidationException::class);
});

it('keeps the unit text when the sales unit does not change, and writes the code when it does', function () {
    $article = ($this->save)($this->owner, $this->company, ['number' => 'T', 'name' => 'Arbete', 'unit' => 'tim', 'price' => '600', 'vat_rate' => 2500]);

    $saved = ($this->save)($this->owner, $this->company, ['number' => 'T', 'name' => 'Arbete 2', 'price' => '600', 'vat_rate' => 2500, 'stock_unit_id' => unitId('h'), 'purchase_unit_id' => unitId('min')], $article);

    expect($saved->fresh())->unit->toBe('tim')->name->toBe('Arbete 2');

    $changed = ($this->save)($this->owner, $this->company, ['number' => 'T', 'name' => 'Arbete 2', 'price' => '600', 'vat_rate' => 2500, 'sales_unit_id' => unitId('min')], $saved);

    expect($changed->fresh()->unit)->toBe('min');
});

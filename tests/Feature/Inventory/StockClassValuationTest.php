<?php

declare(strict_types=1);

use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatPeriod;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Modules\Inventory\Actions\BookStockValuation;
use App\Modules\Inventory\InventoryModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * The year-end stock change per stock class (ADR-073 §9, rule sheet RS-R1):
 * raw materials on 1410/4910 and goods for resale on the company's
 * original pair, in one voucher; a company that never chose a class books
 * exactly as before; and the K2 97 % rule (K2 punkt 12.5), offered to K2
 * companies only, off by default and refused when an article is written
 * down.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None, 'reporting_framework' => ReportingFramework::K2])->save();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K2])->save();
    $this->places = Inventory::locations($this->company, $this->owner);

    // 10 st at 100 kr of goods for resale; 4 kg at 50 kr of raw material.
    $this->goods = Inventory::article($this->company, $this->owner, 'H-1', 'Flaskvatten');
    Inventory::receive($this->company, $this->owner, $this->goods, $this->places['bin1'], '10', '100', '2026-03-01');
    $this->raw = Inventory::article($this->company, $this->owner, 'R-1', 'Mjöl', ['unit' => 'kg']);
    Inventory::receive($this->company, $this->owner, $this->raw, $this->places['bin1'], '4', '50', '2026-03-01');
});

/** @return array<int, int> account => signed öre */
function voucherRows(string $id): array
{
    $voucher = Voucher::query()->with('rows.account')->findOrFail($id);

    return $voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->sort()->all();
}

it('books everything on 1460/4960 for a company that never chose a class, as before', function () {
    $valuation = app(BookStockValuation::class)($this->owner, $this->company, $this->year);

    CurrentCompany::set($this->company);
    expect(voucherRows($valuation->voucher_id))->toBe([4960 => -120_000, 1460 => 120_000])
        ->and($valuation->classes)->toHaveCount(1)
        ->and($valuation->classes[0])->toMatchArray(['classes' => ['GOODS_FOR_RESALE'], 'stock_account' => 1460, 'change_account' => 4960, 'value_ore' => 120_000])
        ->and($valuation->rule_97)->toBeFalse();
});

it('books raw materials on 1410/4910 and goods for resale on 1460/4960 in one voucher', function () {
    $this->raw->forceFill(['stock_class' => 'RAW_MATERIAL'])->save();

    $valuation = app(BookStockValuation::class)($this->owner, $this->company, $this->year);

    CurrentCompany::set($this->company);
    expect(voucherRows($valuation->voucher_id))->toBe([4960 => -100_000, 4910 => -20_000, 1410 => 20_000, 1460 => 100_000])
        ->and($valuation->value_ore)->toBe(120_000)
        ->and(collect($valuation->classes)->pluck('change_ore', 'stock_account')->all())->toBe([1410 => 20_000, 1460 => 100_000])
        ->and(collect($valuation->lines)->pluck('stock_class', 'number')->all())->toBe(['H-1' => 'GOODS_FOR_RESALE', 'R-1' => 'RAW_MATERIAL']);
});

it('uses the default class for articles without one, and its own pair from the settings', function () {
    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $this->company, $this->owner, ['default_stock_class' => 'RAW_MATERIAL', 'raw_material_stock_account' => 1410, 'raw_material_change_account' => 4910]);
    $this->goods->forceFill(['stock_class' => 'GOODS_FOR_RESALE'])->save();

    $valuation = app(BookStockValuation::class)($this->owner, $this->company, $this->year);

    CurrentCompany::set($this->company);
    expect(voucherRows($valuation->voucher_id))->toBe([4960 => -100_000, 4910 => -20_000, 1410 => 20_000, 1460 => 100_000]);
});

it('takes a K2 company\'s stock up at 97 % of cost when it has chosen the rule', function () {
    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $this->company, $this->owner, ['rule_97' => true]);

    $plan = BookStockValuation::plan($this->company, $this->year);
    expect($plan['rule_97'])->toBeTrue()->and($plan['value_ore'])->toBe(116_400);

    $valuation = app(BookStockValuation::class)($this->owner, $this->company, $this->year);
    expect($valuation->value_ore)->toBe(116_400)->and($valuation->cost_value_ore)->toBe(120_000)->and($valuation->rule_97)->toBeTrue();
});

it('refuses the 97 % rule when an article is written down, and ignores it outside K2', function () {
    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $this->company, $this->owner, ['rule_97' => true]);
    $this->raw->forceFill(['net_realisable_value_ore' => 1_000])->save();

    expect(fn () => app(BookStockValuation::class)($this->owner, $this->company, $this->year))->toThrow(ValidationException::class, '97-procentsregeln');

    // The framework belongs to the fiscal year (ADR-059 A.2.1): a K3 year ignores the rule even if the company says K2.
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $valuation = app(BookStockValuation::class)($this->owner, $this->company->fresh(), $this->year->fresh());
    expect($valuation->rule_97)->toBeFalse()->and($valuation->value_ore)->toBe(104_000);
});

it('books a class no longer in use down when the default class changes after a booked balance (P1)', function () {
    // 500 kr already on 1460 from an earlier year-end, then the company calls everything raw material.
    Bookkeeping::post($this->owner, $this->company, 50_000, 1460, 1930, '2026-01-02');
    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $this->company, $this->owner, ['default_stock_class' => 'RAW_MATERIAL']);

    $valuation = app(BookStockValuation::class)($this->owner, $this->company, $this->year);

    CurrentCompany::set($this->company);
    expect(voucherRows($valuation->voucher_id))->toBe([4910 => -120_000, 1460 => -50_000, 4960 => 50_000, 1410 => 120_000])
        ->and($valuation->value_ore)->toBe(120_000)
        ->and($valuation->book_value_ore)->toBe(50_000);
});

it('books 1460 down when the last explicit goods-for-resale article changes class (P1)', function () {
    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $this->company, $this->owner, ['default_stock_class' => 'RAW_MATERIAL']);
    $this->goods->forceFill(['stock_class' => 'GOODS_FOR_RESALE'])->save();
    Bookkeeping::post($this->owner, $this->company, 100_000, 1460, 1930, '2026-01-02');
    $this->goods->forceFill(['stock_class' => null])->save();

    $valuation = app(BookStockValuation::class)($this->owner, $this->company, $this->year);

    CurrentCompany::set($this->company);
    expect(voucherRows($valuation->voucher_id))->toBe([4910 => -120_000, 1460 => -100_000, 4960 => 100_000, 1410 => 120_000]);
});

<?php

declare(strict_types=1);

use App\Actions\Closing\DeferredTaxItems;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Closing\ClosingAccounts;
use App\Domain\Closing\CorporateTaxRules;
use App\Domain\Closing\DeferredTax\BuildingCarryingAmounts;
use App\Domain\Closing\DeferredTaxProposal;
use App\Domain\Closing\TaxProposal;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\DeferredTaxPage;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ClosingCheck;
use App\Models\DeferredTaxItem;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Voucher;
use App\Models\YearEndEntry;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\S1Fixture;

/*
 * S1-P5 (ADR-059 A.2.6, A.4): the deferred tax of a K3 company. F-S1-a's
 * building to the öre (904 752,00 / 995 227,20 / 1 085 702,40 on 2240 and the
 * change 90 475,20 on 8940 each year); untaxed reserves never an item; the
 * rate of the year after the balance date; a deferred tax asset never above
 * what a person recognised (H6); nothing booked without a person's
 * confirmation; the booked entry and its items append-only.
 */

/** @return array<string, mixed> */
function deferredItem(array $overrides = []): array
{
    return [
        'kind' => DeferredTaxItem::BUILDING_COMPONENTS,
        'description' => 'Fabriksbyggnaden',
        'account_from' => '1110',
        'account_to' => '1119',
        'tax_value' => '0',
        'tax_value_source' => DeferredTaxItem::SOURCE_ENTERED,
        ...$overrides,
    ];
}

function k3Company(): array
{
    [$owner, $company] = Bookkeeping::company();
    $company->forceFill(['legal_form' => LegalForm::Ab])->save();
    CurrentCompany::set($company);
    test()->actingAs($owner);
    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    foreach ([1110, 1119, 1220, 1221, 1222, 1223, 1224, 2290, 6000, 7820] as $number) {
        Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
    }

    return [$owner, $company, $year->refresh()];
}

describe('F-S1-a', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
        // The deferred tax vouchers are what NONOX proposes; the rest of the ledger is posted.
        $this->fixture = S1Fixture::load('f-s1-a', fn (array $voucher): bool => ($voucher['proposal_kind'] ?? null) !== 'deferred_tax');
        CurrentCompany::set($this->fixture->company);
        $this->actingAs($this->fixture->owner);
        $this->items = app(DeferredTaxItems::class);
        $taxValues = json_decode((string) file_get_contents(base_path('tests/Fixtures/s1/f-s1-a/tax_values.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->taxValues = $taxValues['building_B1']['tax_values'];
    });

    it('S1-R4 proposes 904 752,00 → 995 227,20 → 1 085 702,40 on 2240 and 90 475,20 on 8940 to the öre', function () {
        $expected = $this->fixture->expected['deferred_tax'];

        foreach ([2025 => ['2024-12-31', '2025-12-31'], 2026 => ['2025-12-31', '2026-12-31']] as $label => [$openingDate, $closingDate]) {
            $year = $this->fixture->years[$label];
            $this->items->save($this->fixture->owner, $year, deferredItem(['tax_value' => (string) intdiv($this->taxValues[$closingDate], 100)]));

            $proposal = DeferredTaxProposal::for($this->fixture->company, $year);
            $line = $proposal->lines[0];

            expect($proposal->blockers)->toBe([])
                ->and($proposal->rateBp)->toBe($expected['rate_bp'])
                ->and($line['carrying_ore'])->toBe($expected['items'][$closingDate]['byggnad_redovisat_varde'])
                ->and($line['tax_value_ore'])->toBe($expected['items'][$closingDate]['byggnad_skattemassigt_varde'])
                ->and($line['difference_ore'])->toBe($expected['items'][$closingDate]['temporar_skillnad'])
                ->and($line['nature'])->toBe(DeferredTaxProposal::TAXABLE)
                ->and($proposal->openingLiabilityOre)->toBe($expected['items'][$openingDate]['uppskjuten_skatteskuld'])
                ->and($proposal->targetLiabilityOre())->toBe($expected['items'][$closingDate]['uppskjuten_skatteskuld'])
                ->and($proposal->targetAssetOre())->toBe(0)
                ->and($proposal->changeSinceOpeningOre())->toBe($expected['change_booked'][(string) $label])
                ->and($proposal->expenseOre())->toBe(9_047_520)
                ->and($proposal->voucherRows())->toBe([
                    ['account' => 8940, 'amount' => 9_047_520],
                    ['account' => 2240, 'amount' => -9_047_520],
                ]);
        }

        expect($this->fixture->expected['deferred_tax']['items']['2024-12-31']['uppskjuten_skatteskuld'])->toBe(90_475_200)
            ->and($this->fixture->expected['deferred_tax']['items']['2026-12-31']['uppskjuten_skatteskuld'])->toBe(108_570_240);
    });

    it('S1-R4.6 books the confirmed change once, on 8940 against 2240, through the voucher path, and freezes the items', function () {
        $year = $this->fixture->years[2026];
        $item = $this->items->save($this->fixture->owner, $year, deferredItem(['tax_value' => '11 481 600']));
        $vouchers = Voucher::query()->count();

        $entry = app(YearEndClosing::class)->confirmDeferredTax($this->fixture->owner, $year, 9_047_520);

        $voucher = Voucher::query()->findOrFail($entry->voucher_id);
        $rows = $voucher->rows()->with('account')->get()->mapWithKeys(fn ($row): array => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();

        expect(Voucher::query()->count())->toBe($vouchers + 1)
            ->and($entry->type)->toBe(YearEndEntry::DEFERRED_TAX)
            ->and($entry->amount_ore->ore)->toBe(9_047_520)
            ->and($voucher->source)->toBe(VoucherSource::Closing)
            ->and($voucher->voucher_date->toDateString())->toBe('2026-12-31')
            ->and($voucher->series->code)->toBe('A')
            ->and($rows)->toBe([8940 => 9_047_520, 2240 => -9_047_520])
            ->and($item->refresh()->rate_bp)->toBe(2060)
            ->and($item->confirmed_by)->toBe($this->fixture->owner->id)
            // Booked: the proposal now has nothing left to book.
            ->and(DeferredTaxProposal::for($this->fixture->company, $year)->expenseOre())->toBe(0);

        expect(fn () => app(YearEndClosing::class)->confirmDeferredTax($this->fixture->owner, $year, 0))
            ->toThrow(ValidationException::class, 'Årets uppskjutna skatt är redan bokförd');
        expect(fn () => $this->items->save($this->fixture->owner, $year, deferredItem(['tax_value' => '1'])))
            ->toThrow(ValidationException::class, 'redan bokförd');
    });
});

describe('a K3 company', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
        [$this->owner, $this->company, $this->year] = k3Company();
        $this->items = app(DeferredTaxItems::class);
        $this->closing = app(YearEndClosing::class);
        // A building of 1 000 000 kr on 1110, depreciated 100 000 kr in the books.
        Bookkeeping::post($this->owner, $this->company, 100_000_000, 1110, 1930, '2026-01-10');
        Bookkeeping::post($this->owner, $this->company, 10_000_000, 7820, 1119, '2026-12-31');
    });

    it('S1-R4.2 c never takes untaxed reserves as an item', function (string $from, string $to) {
        expect(fn () => $this->items->save($this->owner, $this->year, deferredItem(['kind' => DeferredTaxItem::OTHER_LIABILITY, 'account_from' => $from, 'account_to' => $to])))
            ->toThrow(ValidationException::class, 'Obeskattade reserver (konto 2100–2199) kan inte vara en post');
        expect(DeferredTaxItem::query()->count())->toBe(0);
    })->with([['2124', '2124'], ['2100', '2199'], ['2050', '2150'], ['2190', '2300']]);

    it('S1-R4.2 c is backed by the database', function () {
        expect(fn () => DB::table('deferred_tax_items')->insert([
            'company_id' => $this->company->id, 'fiscal_year_id' => $this->year->id, 'kind' => DeferredTaxItem::OTHER_LIABILITY,
            'description' => 'x', 'account_from' => 2120, 'account_to' => 2129, 'tax_value_ore' => 0, 'tax_value_source' => 'ENTERED',
            'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses the deferred tax accounts and accounts on the wrong side as an item', function () {
        expect(fn () => $this->items->save($this->owner, $this->year, deferredItem(['account_from' => '1370', 'account_to' => '1379'])))
            ->toThrow(ValidationException::class, 'Kontona för uppskjuten skatt');
        expect(fn () => $this->items->save($this->owner, $this->year, deferredItem(['account_from' => '2440', 'account_to' => '2440'])))
            ->toThrow(ValidationException::class, 'tillgångskonton');
        expect(fn () => $this->items->save($this->owner, $this->year, deferredItem(['kind' => DeferredTaxItem::OTHER_LIABILITY, 'account_from' => '1510', 'account_to' => '1510'])))
            ->toThrow(ValidationException::class, 'skuldkonton');
    });

    it('S1-R4.1 reads a liability item credit positive and turns carrying above tax value into an asset', function () {
        Bookkeeping::post($this->owner, $this->company, 5_000_000, 6000, 2290, '2026-12-31');
        $this->items->save($this->owner, $this->year, deferredItem(['kind' => DeferredTaxItem::OTHER_LIABILITY, 'description' => 'Garantiavsättning', 'account_from' => '2290', 'account_to' => '2290', 'tax_value' => '0']));

        $line = DeferredTaxProposal::for($this->company, $this->year)->lines[0];

        expect($line['carrying_ore'])->toBe(5_000_000)
            ->and($line['nature'])->toBe(DeferredTaxProposal::DEDUCTIBLE)
            ->and($line['gross_ore'])->toBe(1_030_000)
            // H6: not recognised by a person, so no asset.
            ->and($line['asset_ore'])->toBe(0);
    });

    it('H6 never proposes a deferred tax asset on a loss carry-forward and never books more than a person recognised', function () {
        $item = $this->items->save($this->owner, $this->year, deferredItem(['kind' => DeferredTaxItem::LOSS_CARRY_FORWARD, 'description' => 'Underskott 2025', 'account_from' => '', 'account_to' => '', 'tax_value' => '500 000']));
        $proposal = DeferredTaxProposal::for($this->company, $this->year);

        expect($proposal->grossAssetOre())->toBe(10_300_000)
            ->and($proposal->assetOre())->toBe(0)
            ->and($proposal->expenseOre())->toBe(0);

        expect(fn () => $this->items->save($this->owner, $this->year, deferredItem(['id' => $item->id, 'kind' => DeferredTaxItem::LOSS_CARRY_FORWARD, 'account_from' => '', 'account_to' => '', 'tax_value' => '500 000', 'recognised' => '40 000'])))
            ->toThrow(ValidationException::class, 'Skriv varför fordran tas upp');
        expect(fn () => $this->items->save($this->owner, $this->year, deferredItem(['id' => $item->id, 'kind' => DeferredTaxItem::LOSS_CARRY_FORWARD, 'account_from' => '', 'account_to' => '', 'tax_value' => '500 000', 'recognised' => '103 000,01', 'recognition_reason' => 'Orderstock'])))
            ->toThrow(ValidationException::class, 'Fordran kan vara högst 103');
        expect($item->refresh()->recognised_ore->ore)->toBe(0);

        $this->items->save($this->owner, $this->year, deferredItem(['id' => $item->id, 'kind' => DeferredTaxItem::LOSS_CARRY_FORWARD, 'description' => 'Underskott 2025', 'account_from' => '', 'account_to' => '', 'tax_value' => '500 000', 'recognised' => '40 000', 'recognition_reason' => 'Avtalad orderstock för 2027']));
        $proposal = DeferredTaxProposal::for($this->company, $this->year);

        expect($proposal->assetOre())->toBe(4_000_000)
            ->and($proposal->voucherRows())->toBe([
                ['account' => 8940, 'amount' => -4_000_000],
                ['account' => 1370, 'amount' => 4_000_000],
            ]);

        // The loss shrinks below what was recognised: the asset follows the smaller amount.
        DB::table('deferred_tax_items')->where('id', $item->id)->update(['tax_value_ore' => 10_000_000]);

        expect(DeferredTaxProposal::for($this->company, $this->year)->assetOre())->toBe(2_060_000);
    });

    it('S1-R4.6 keeps liability and asset gross unless the person confirms a legal right of set-off', function () {
        $this->items->save($this->owner, $this->year, deferredItem(['tax_value' => '700 000']));
        $this->items->save($this->owner, $this->year, deferredItem(['kind' => DeferredTaxItem::LOSS_CARRY_FORWARD, 'description' => 'Underskott', 'account_from' => '', 'account_to' => '', 'tax_value' => '100 000', 'recognised' => '20 600', 'recognition_reason' => 'Vinst budgeterad']));

        $gross = DeferredTaxProposal::for($this->company, $this->year);
        $net = DeferredTaxProposal::for($this->company, $this->year, setOff: true);

        // Building: 900 000 − 700 000 = 200 000 × 20,6 % = 41 200; loss: 20 600 recognised.
        expect([$gross->targetLiabilityOre(), $gross->targetAssetOre()])->toBe([4_120_000, 2_060_000])
            ->and([$net->targetLiabilityOre(), $net->targetAssetOre()])->toBe([2_060_000, 0])
            ->and($gross->expenseOre())->toBe($net->expenseOre());

        $this->closing->confirmDeferredTax($this->owner, $this->year, 2_060_000, setOff: true);

        expect(ClosingCheck::query()->where('fiscal_year_id', $this->year->id)->where('key', DeferredTaxProposal::SET_OFF_CHECK)->exists())->toBeTrue();
    });

    it('books nothing without the person\'s confirmation of the amount they saw', function () {
        $vouchers = Voucher::query()->count();
        $this->items->save($this->owner, $this->year, deferredItem(['tax_value' => '700 000']));
        DeferredTaxProposal::for($this->company, $this->year);
        Livewire::test(DeferredTaxPage::class)->assertOk();

        expect(Voucher::query()->count())->toBe($vouchers)
            ->and(YearEndEntry::query()->count())->toBe(0);

        // The books change after the proposal was shown: refused.
        Bookkeeping::post($this->owner, $this->company, 1_000_000, 7820, 1119, '2026-12-31');

        expect(fn () => $this->closing->confirmDeferredTax($this->owner, $this->year, 4_120_000))
            ->toThrow(ValidationException::class, 'Beräkningen har ändrats sedan den visades');
        expect(YearEndEntry::query()->count())->toBe(0);
    });

    it('records a confirmation without a voucher when nothing changes', function () {
        $vouchers = Voucher::query()->count();
        $entry = $this->closing->confirmDeferredTax($this->owner, $this->year, 0);

        expect($entry->voucher_id)->toBeNull()
            ->and($entry->amount_ore->ore)->toBe(0)
            ->and(Voucher::query()->count())->toBe($vouchers);
    });

    it('leaves the current tax proposal open after the deferred tax is booked on 8940', function () {
        $this->items->save($this->owner, $this->year, deferredItem(['tax_value' => '700 000']));
        $this->closing->confirmDeferredTax($this->owner, $this->year, 4_120_000);
        $this->closing->answerTaxStatus($this->owner, $this->year, TaxProposal::STATUS_ORDINARY);
        $proposal = TaxProposal::for($this->company, $this->year, null);

        expect($proposal->taxBookedByHand())->toBeFalse()
            ->and($proposal->blockers())->toBe([]);

        // Current tax booked by hand still blocks it.
        ClosingAccounts::ensure(2512);
        ClosingAccounts::ensure(8910);
        Bookkeeping::post($this->owner, $this->company, 100_000, 8910, 2512, '2026-12-31');

        expect(TaxProposal::for($this->company, $this->year, null)->taxBookedByHand())->toBeTrue();
    });

    it('is booked before the result', function () {
        YearEndEntry::create(['fiscal_year_id' => $this->year->id, 'type' => YearEndEntry::RESULT, 'amount_ore' => 0, 'created_by' => $this->owner->id]);

        expect(fn () => $this->closing->confirmDeferredTax($this->owner, $this->year, 0))
            ->toThrow(ValidationException::class, 'Bokför den uppskjutna skatten före resultatet');
    });

    it('keeps the booked entry and the confirmed items append-only, also in the database', function () {
        $item = $this->items->save($this->owner, $this->year, deferredItem(['tax_value' => '700 000']));
        $entry = $this->closing->confirmDeferredTax($this->owner, $this->year, 4_120_000);
        $item->refresh();

        expect(fn () => $entry->update(['amount_ore' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class)
            ->and(fn () => $item->update(['tax_value_ore' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $item->delete())->toThrow(LogicException::class)
            ->and(fn () => DB::table('deferred_tax_items')->where('id', $item->id)->update(['tax_value_ore' => 1]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('deferred_tax_items')->where('id', $item->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('year_end_entries')->where('id', $entry->id)->update(['amount_ore' => 1]))->toThrow(QueryException::class);
    });

    it('S1-R4.3 refuses when the data holds another rate for a later year', function () {
        $this->items->save($this->owner, $this->year, deferredItem(['tax_value' => '700 000']));
        $property = new ReflectionProperty(CorporateTaxRules::class, 'data');
        $original = CorporateTaxRules::rates();
        $data = json_decode((string) file_get_contents(database_path('data/corporate-tax/rules.json')), true, flags: JSON_THROW_ON_ERROR);
        $data['rates'][0]['starts_before'] = '2028-01-01';
        $data['rates'][] = [...$original[0], 'starts_from' => '2028-01-01', 'starts_before' => null, 'rate_bp' => 1900];
        $property->setValue(null, $data);

        try {
            $proposal = DeferredTaxProposal::for($this->company, $this->year);

            expect($proposal->rateBp)->toBeNull()
                ->and($proposal->blockers[0])->toContain('Beräkna den uppskjutna skatten för hand');
            expect(fn () => $this->closing->confirmDeferredTax($this->owner, $this->year, 0))->toThrow(ValidationException::class, 'för hand');
        } finally {
            $property->setValue(null, null);
        }
    });

    it('takes a building\'s carrying amount from the register when S1-P4 binds one', function () {
        $asset = FixedAsset::create([
            'number' => 1, 'name' => 'Fabriken', 'category' => 'EQUIPMENT', 'acquired_on' => '2026-01-10', 'acquisition_ore' => 100_000_000,
            'useful_life_months' => 600, 'depreciation_start' => '2026-01-01', 'asset_account' => 1110, 'accumulated_account' => 1119,
            'expense_account' => 7820, 'created_by' => $this->owner->id,
        ]);
        app()->instance(BuildingCarryingAmounts::class, new class($asset->id) implements BuildingCarryingAmounts
        {
            public function __construct(private int $id) {}

            public function forYear(FiscalYear $fiscalYear): array
            {
                return [$this->id => ['label' => 'Fabriken', 'carrying_ore' => 80_000_000]];
            }
        });

        $this->items->save($this->owner, $this->year, deferredItem(['fixed_asset_id' => $asset->id, 'account_from' => '', 'account_to' => '', 'tax_value' => '700 000']));
        $line = DeferredTaxProposal::for($this->company, $this->year)->lines[0];

        expect($line['carrying_ore'])->toBe(80_000_000)
            ->and($line['carrying_source'])->toBe(DeferredTaxProposal::CARRYING_REGISTER)
            ->and($line['liability_ore'])->toBe(2_060_000);
    });

    it('refuses a building from the register while no register is bound', function () {
        expect(fn () => $this->items->save($this->owner, $this->year, deferredItem(['fixed_asset_id' => 999_999, 'account_from' => '', 'account_to' => '', 'tax_value' => '1'])))
            ->toThrow(ValidationException::class, 'Byggnaden finns inte i anläggningsregistret');
    });

    it('writes ids, enums and amounts to the audit log, never the description or the reason', function () {
        $this->items->save($this->owner, $this->year, deferredItem(['tax_value' => '700 000', 'description' => 'Hemlig byggnad']));
        $this->items->save($this->owner, $this->year, deferredItem(['kind' => DeferredTaxItem::LOSS_CARRY_FORWARD, 'description' => 'Underskott', 'account_from' => '', 'account_to' => '', 'tax_value' => '100 000', 'recognised' => '1 000', 'recognition_reason' => 'Hemligt skäl']));
        $this->closing->confirmDeferredTax($this->owner, $this->year, 4_020_000);

        $logs = AuditLog::query()->whereIn('action', ['deferred_tax.item_created', 'closing.deferred_tax_confirmed'])->get();
        $json = $logs->map(fn (AuditLog $log): string => json_encode($log->payload, JSON_THROW_ON_ERROR))->implode("\n");

        expect($logs)->toHaveCount(3)
            ->and($json)->not->toContain('Hemlig')
            ->and($json)->toContain('"expense_ore":4020000');
    });

    it('needs fiscal-years:manage to change or book, per role', function (string $role, bool $allowed) {
        $member = Bookkeeping::member($this->company, $role);
        $attempt = fn () => $this->items->save($member, $this->year, deferredItem(['tax_value' => '700 000']));
        $confirm = fn () => $this->closing->confirmDeferredTax($member, $this->year, 0);

        if ($allowed) {
            expect($attempt()->id)->toBeInt()
                ->and($this->closing->confirmDeferredTax($member, $this->year, 4_120_000)->type)->toBe(YearEndEntry::DEFERRED_TAX);

            return;
        }

        expect($attempt)->toThrow(AuthorizationException::class)
            ->and($confirm)->toThrow(AuthorizationException::class);
    })->with([
        'admin' => ['admin', true],
        'accountant' => ['accountant', true],
        'bookkeeper' => ['bookkeeper', false],
        'approver' => ['approver', false],
        'auditor' => ['auditor', false],
    ]);

    it('shows the page to an auditor without the form, and refuses a role without reports:view', function () {
        $this->items->save($this->owner, $this->year, deferredItem(['tax_value' => '700 000']));
        $auditor = Bookkeeping::member($this->company, 'auditor');
        $approver = Bookkeeping::member($this->company, 'approver');

        $this->actingAs($auditor);
        $this->get(route('accounting.closing.deferredTax', ['ar' => $this->year->id]))
            ->assertOk()
            ->assertSee('Fabriksbyggnaden')
            ->assertDontSee('Lägg till posten')
            ->assertDontSee('Bekräfta och bokför uppskjuten skatt');

        $this->actingAs($approver);
        $this->get(route('accounting.closing.deferredTax'))->assertForbidden();
    });

    it('lets the owner add an item and book it on the page, with the consequence stated in Swedish', function () {
        $this->get(route('accounting.closing.deferredTax', ['ar' => $this->year->id]))->assertOk()->assertSee('Uppskjuten skatt');

        Livewire::test(DeferredTaxPage::class)
            ->set('description', 'Fabriksbyggnaden')
            ->set('accountFrom', '1110')
            ->set('accountTo', '1119')
            ->set('taxValue', '700 000')
            ->call('save')
            ->assertDispatched('toast', message: 'Posten är tillagd.')
            ->assertSee('Fabriksbyggnaden')
            ->assertSee('Bokföra förändringen av uppskjuten skatt, 41')
            ->assertSee('En bokförd verifikation kan inte ändras, bara rättas med en ny.')
            ->call('confirm', 4_120_000)
            ->assertDispatched('toast', message: 'Förändringen av uppskjuten skatt, '.Money::ofOre(4_120_000)->format().', är bokförd.');

        expect(YearEndEntry::query()->where('type', YearEndEntry::DEFERRED_TAX)->first()?->amount_ore->ore)->toBe(4_120_000);
    });

    it('resists tampering with the year, the item ids and the confirmed amount', function () {
        [$otherOwner, $otherCompany, $otherYear] = k3Company();
        $otherItem = app(DeferredTaxItems::class)->save($otherOwner, $otherYear, deferredItem(['tax_value' => '1']));
        CurrentCompany::set($this->company);
        $this->actingAs($this->owner);
        $this->items->save($this->owner, $this->year, deferredItem(['tax_value' => '700 000']));

        // Another company's year and items answer 404, as one that does not exist.
        Livewire::test(DeferredTaxPage::class)->set('year', $otherYear->id)->assertNotFound();
        Livewire::test(DeferredTaxPage::class)->call('edit', $otherItem->id)->assertNotFound();
        Livewire::test(DeferredTaxPage::class)->call('remove', $otherItem->id)->assertNotFound();
        Livewire::test(DeferredTaxPage::class)->set('editing', $otherItem->id)->set('description', 'x')->set('accountFrom', '1110')->set('accountTo', '1119')->set('taxValue', '1')->call('save')->assertNotFound();
        $this->get(route('accounting.closing.deferredTax', ['ar' => $otherYear->id]))->assertOk()->assertDontSee('Fabriksbyggnaden ');

        Livewire::test(DeferredTaxPage::class)->call('confirm', 1)
            ->assertDispatched('toast', type: 'error', message: 'Beräkningen har ändrats sedan den visades. Granska den igen innan du bekräftar.');

        CurrentCompany::set($otherCompany);

        expect(DeferredTaxItem::query()->whereKey($otherItem->id)->value('description'))->toBe('Fabriksbyggnaden')
            ->and(YearEndEntry::query()->count())->toBe(0);
    });

    it('runs a fixed number of queries however many items and vouchers there are', function () {
        $this->items->save($this->owner, $this->year, deferredItem(['tax_value' => '700 000']));
        $count = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            Livewire::test(DeferredTaxPage::class)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        // The first render warms the user's company; measure from the second.
        $count();
        $short = $count();

        foreach (range(1, 10) as $index) {
            Bookkeeping::post($this->owner, $this->company, 1_000_000, 1220 + ($index % 5), 1930, '2026-06-01');
            $this->items->save($this->owner, $this->year, deferredItem(['kind' => DeferredTaxItem::OTHER_ASSET, 'description' => "Post {$index}", 'account_from' => (string) (1220 + ($index % 5)), 'account_to' => (string) (1220 + ($index % 5)), 'tax_value' => '1']));
        }

        expect($count())->toBe($short);
    });

    it('refuses a K2 year and a company that pays no income tax of its own', function () {
        $this->year->forceFill(['reporting_framework' => ReportingFramework::K2])->save();

        expect(fn () => $this->items->save($this->owner, $this->year->refresh(), deferredItem(['tax_value' => '1'])))->toThrow(ValidationException::class, 'bara av företag som tillämpar K3');
        expect(fn () => $this->closing->confirmDeferredTax($this->owner, $this->year, 0))->toThrow(ValidationException::class, 'K3');

        $this->year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
        $this->company->forceFill(['legal_form' => LegalForm::Hb])->save();

        expect(fn () => $this->closing->confirmDeferredTax($this->owner, $this->year->refresh(), 0))->toThrow(ValidationException::class, 'ägarna beskattas');
        Livewire::test(DeferredTaxPage::class)->assertSee('ägarna beskattas');
    });
});

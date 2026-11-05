<?php

declare(strict_types=1);

use App\Actions\IncomeTax\BusinessIncomeTaxReturns;
use App\Domain\Accounting\AccountType;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\IncomeTax\Ne;
use App\Livewire\Accounting\IncomeTaxPage;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Models\OpeningBalance;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * The NE-bilaga of a sole trader (ADR-053): the fields from the books, the
 * tax adjustments NONOX calculates from what the owner confirms, and the
 * SRU file for Skatteverket's INK1 e-service.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['legal_form' => LegalForm::Ef, 'org_number' => '811218-9876', 'legal_name' => 'Ekens Snickeri', 'postal_code' => '222 33', 'city' => 'Lund', 'vat_registered' => true, 'vat_period' => VatPeriod::Yearly])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->returns = app(BusinessIncomeTaxReturns::class);

    // Sales 100 000 + VAT, purchases 20 000, other costs 10 000, interest 500 in and 300 out,
    // inventarier 50 000 depreciated by 5 000.
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1930 => 12_500_000, 3001 => -10_000_000, 2611 => -2_500_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-04-01', [4010 => 2_000_000, 1930 => -2_000_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-05-01', [5010 => 1_000_000, 1930 => -1_000_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-06-01', [1930 => 50_000, 8310 => -50_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-07-01', [8410 => 30_000, 1930 => -30_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-08-01', [1220 => 5_000_000, 1930 => -5_000_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-12-31', [7832 => 500_000, 1229 => -500_000]);
});

/** @param  array<string, string>  $adjustments @param  array<string, mixed>  $inputs */
function neSaved(object $test, array $adjustments = [], array $inputs = [], ?string $century = '19'): Ne
{
    $test->returns->saveNe($test->owner, $test->year, $adjustments, $inputs, $century, null);

    return Ne::for($test->company->fresh(), $test->year, IncomeTaxReturn::query()->where('form', 'NE')->sole());
}

it('fills B1–B16 and R1–R11 from the books in whole kronor', function () {
    $ne = Ne::for($this->company, $this->year, null);
    $fields = $ne->ne();

    expect($ne->period())->toBe('2026P4')
        ->and($fields)->toMatchArray([
            7011 => '20260101', 7012 => '20261231',
            7212 => 45_000, 7280 => 45_200, 7383 => 25_000, 7300 => 65_200,
            7400 => 100_000, 7403 => 500, 7500 => 20_000, 7501 => 10_000, 7503 => 300, 7505 => 5_000,
            7440 => 65_200, 7600 => 65_200,
        ])
        ->and($fields)->not->toHaveKey(7401)
        ->and($fields[7300])->toBe($fields[7440]);
});

it('puts exempt sales in R2, by the account’s VAT box, and all sales of a company not registered for VAT', function () {
    Account::query()->where('number', 3004)->update(['vat_box' => 42]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-09-01', [1930 => 1_000_000, 3004 => -1_000_000]);

    expect(Ne::for($this->company, $this->year, null)->ne())->toMatchArray([7400 => 100_000, 7401 => 10_000]);

    $this->company->forceFill(['vat_registered' => false, 'vat_period' => VatPeriod::None])->save();
    expect(Ne::for($this->company->fresh(), $this->year, null)->ne())->toMatchArray([7401 => 110_000])->not->toHaveKey(7400);
});

it('needs the century of birth for the personnummer, and stores it encrypted', function () {
    expect(fn () => Ne::for($this->company, $this->year, null)->identity())->toThrow(ValidationException::class);

    $ne = neSaved($this);

    expect($ne->identity())->toBe('198112189876')
        ->and($ne->birthYear())->toBe(1981)
        ->and((string) DB::table('income_tax_returns')->value('personal'))->not->toContain('century');
});

it('calculates the schablonavdrag for egenavgifter by year of birth, and 20 % for passive business', function (string $org, ?string $century, bool $passive, ?int $rate) {
    $this->company->forceFill(['org_number' => $org])->save();
    $ne = neSaved($this, inputs: ['passive' => $passive], century: $century);
    $calc = $ne->calculation();

    expect($ne->selfEmploymentRate())->toBe($rate)
        ->and($calc['r43'])->toBe($rate === null ? null : intdiv(65_200 * $rate, 10_000))
        ->and($calc['result'])->toBe(65_200 - ($calc['r43'] ?? 0));
})->with([
    // Skatteverket's test number, and numbers that fail the Luhn check so they can never be anyone's personnummer.
    'born 1981' => ['811218-9876', '19', false, 2500],
    'born 1959' => ['590101-1236', '19', false, 2500],
    'born 1958' => ['581231-1238', '19', false, 1000],
    'born 1938' => ['380101-1230', '19', false, 1000],
    // SAL övergångsbestämmelse 6: no egenavgifter, so no schablonavdrag.
    'born 1937' => ['371231-1230', '19', false, 0],
    'passive' => ['811218-9876', '19', true, 2000],
    'passive, born 1937' => ['371231-1230', '19', true, null],
    'no century' => ['811218-9876', null, false, null],
]);

it('lets the owner replace the calculated R43, and says when it cannot be calculated', function () {
    $this->company->forceFill(['org_number' => '371231-1230'])->save();

    expect(neSaved($this)->warnings())->not->toContain('NONOX räknar inte ut schablonavdraget för egenavgifter vid passiv näringsverksamhet för den som är född 1937 eller tidigare. Fyll i R43 själv.');

    $ne = neSaved($this, inputs: ['passive' => true]);
    expect($ne->warnings())->toContain('NONOX räknar inte ut schablonavdraget för egenavgifter vid passiv näringsverksamhet för den som är född 1937 eller tidigare. Fyll i R43 själv.');

    $ne = neSaved($this, ['7714' => '4 000']);
    expect($ne->calculation()['r43'])->toBe(4_000)->and($ne->ne()[7630])->toBe(61_200);
});

it('caps positive räntefördelning at R29 and saves the rest', function () {
    // 1 000 000 × 8,55 % = 85 500, more than the 65 200 surplus.
    $ne = neSaved($this, inputs: ['capital_base' => '1 000 000', 'positive_allocation' => '85 500']);
    $calc = $ne->calculation();

    expect($calc['positive_max'])->toBe(85_500)
        ->and($calc['r30'])->toBe(65_200)
        ->and($calc['saved'])->toBe(20_300)
        ->and($ne->ne())->toMatchArray([7708 => 65_200, 8009 => 1_000_000, 8011 => 20_300]);

    // Choosing less saves more.
    $calc = neSaved($this, inputs: ['capital_base' => '1 000 000', 'positive_allocation' => '10 000'])->calculation();
    expect($calc['r30'])->toBe(10_000)->and($calc['saved'])->toBe(75_500);
});

it('makes negative räntefördelning only below minus 500 000 kronor', function (string $base, int $expected) {
    $ne = neSaved($this, inputs: ['capital_base' => $base]);

    expect($ne->calculation()['r31'])->toBe($expected);

    if ($expected > 0) {
        expect($ne->ne())->toMatchArray([7607 => $expected, 8010 => 500_001]);
    }
})->with([
    'exactly −500 000' => ['-500 000', 0],
    '−500 001' => ['-500 001', 17_750],
]);

it('limits the periodiseringsfond to 30 % of R33 and the expansionsfond to the result and 125,94 % of its underlag', function () {
    $ne = neSaved($this, ['7709' => '50 000']);
    $calc = $ne->calculation();

    expect($calc['r34_max'])->toBe(19_560)->and($calc['r34'])->toBe(19_560)
        ->and($ne->warnings())->toContain("Avsättningen till periodiseringsfond är större än 30 % av överskottet i R33 och har satts till 19\u{a0}560\u{a0}kr.");

    $ne = neSaved($this, ['7710' => '200 000'], ['expansion_capital_base' => '40 000']);
    expect($ne->calculation()['r36'])->toBe(50_376)->and($ne->ne()[8012])->toBe(40_000);
});

it('blocks the calculations for an inkomstår without verified parameters', function () {
    $year = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    $ne = Ne::for($this->company, $year, null);

    expect($ne->hasParameters())->toBeFalse()
        ->and($ne->warnings()[0])->toContain('Statslåneräntan och procentsatserna för inkomståret 2027 finns inte i NONOX än')
        ->and(fn () => $this->returns->sruZip($this->owner, $year))->toThrow(ValidationException::class);
});

it('writes the NE as an SRU file, golden', function () {
    neSaved($this, ['7713' => '1 000'], ['activity' => 'Snickeri', 'capital_base' => '100 000', 'positive_allocation' => '8 550']);
    $files = Bookkeeping::unzipSru($this->returns->sruZip($this->owner, $this->year));
    $blanketter = preg_replace('/^#IDENTITET (\d+) \d{8} \d{6}$/m', '#IDENTITET $1', $files['BLANKETTER.SRU']);

    expect($files['INFO.SRU'])->toContain("#ORGNR 198112189876\n")->toContain("#POSTNR 22233\n")
        ->and($blanketter)->toBe(mb_convert_encoding(implode("\n", [
            '#BLANKETT NE-2026P4',
            '#IDENTITET 198112189876',
            '#NAMN Ekens Snickeri',
            '#UPPGIFT 7011 20260101',
            '#UPPGIFT 7012 20261231',
            '#UPPGIFT 7020 Snickeri',
            '#UPPGIFT 7023 X',
            '#UPPGIFT 7212 45000',
            '#UPPGIFT 7280 45200',
            '#UPPGIFT 7383 25000',
            '#UPPGIFT 7300 65200',
            '#UPPGIFT 7400 100000',
            '#UPPGIFT 7403 500',
            '#UPPGIFT 7500 20000',
            '#UPPGIFT 7501 10000',
            '#UPPGIFT 7503 300',
            '#UPPGIFT 7505 5000',
            '#UPPGIFT 7440 65200',
            '#UPPGIFT 7600 65200',
            '#UPPGIFT 7713 1000',
            '#UPPGIFT 7708 8550',
            '#UPPGIFT 7714 13912',
            '#UPPGIFT 7630 41738',
            '#UPPGIFT 8009 100000',
            '#BLANKETTSLUT',
            '#FIL_SLUT',
        ])."\n", 'ISO-8859-1', 'UTF-8'));
});

it('records which fields were saved, never the amounts or the century', function () {
    neSaved($this, ['7601' => '12 345']);
    $payload = json_encode(AuditLog::query()->where('action', 'income_tax.saved')->latest('id')->firstOrFail()->payload);

    expect($payload)->toContain('7601')->not->toContain('12345')->not->toContain('century');
});

it('shows the NE flow on the income tax page, and only to those who close the year', function () {
    Livewire::test(IncomeTaxPage::class)->assertOk()
        ->assertSee('NE-bilagan, fält för fält')
        ->assertSee('Räntefördelning (R30, R31)')
        ->assertDontSee('Inte för den här företagsformen')
        ->set('century', '19')->call('save')->assertDispatched('toast', message: 'Deklarationsuppgifterna är sparade.')
        ->assertSee('Så lämnar du NE-bilagan');

    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));
    Livewire::test(IncomeTaxPage::class)->assertForbidden();
});

it('answers 404 for another company’s fiscal year', function () {
    [, $other] = Bookkeeping::company();
    $otherYear = CompanyScope::bypass(fn () => FiscalYear::query()->where('company_id', $other->id)->firstOrFail());

    $this->get(route('accounting.incomeTax.sru', $otherYear->id))->assertNotFound();
});

it('reads the NE with a fixed number of queries, however many vouchers', function () {
    $count = function (): int {
        $return = IncomeTaxReturn::query()->where('form', 'NE')->first();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Ne::for($this->company, $this->year, $return)->ne();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count();

    foreach (range(1, 10) as $day) {
        Bookkeeping::postRows($this->owner, $this->company, sprintf('2026-10-%02d', $day), [1930 => 125_000, 3001 => -100_000, 2611 => -25_000]);
    }

    expect($count())->toBe($few);
});

it('never makes positive räntefördelning until the owner chooses, and says what it does to pension and SGI', function () {
    $ne = neSaved($this, inputs: ['capital_base' => '100 000']);

    expect($ne->calculation())->toMatchArray(['r30' => 0, 'usable' => 8_550, 'saved' => 8_550])
        ->and($ne->ne())->not->toHaveKey(7708)->and($ne->ne())->toMatchArray([8009 => 100_000, 8011 => 8_550])
        ->and($ne->warnings())->toContain("Välj hur mycket positiv räntefördelning du vill göra, högst 8\u{a0}550\u{a0}kr. Tills du har valt görs ingen, och beloppet sparas till nästa år.");

    Livewire::test(IncomeTaxPage::class)
        ->assertSee('Positiv räntefördelning är frivillig (inkomstskattelagen 33 kap. 6 §).')
        ->assertSee('sjukpenninggrundande inkomst (SGI)')
        ->assertSee("Du kan räntefördela högst 8\u{a0}550\u{a0}kr i år.");
});

/*
 * Sparat fördelningsbelopp (IL 33 kap. 7–8 §§; SKV 306 p. 9). Worked example 1's
 * books give R29 = 270 400: sales 400 000, interest 1 000 in and 600 out,
 * purchases 80 000, other costs 40 000, depreciation 10 000.
 */
function neExampleOne(object $test): void
{
    Bookkeeping::postRows($test->owner, $test->company, '2026-09-01', [1930 => 30_000_000, 3001 => -30_000_000]);
    Bookkeeping::postRows($test->owner, $test->company, '2026-09-02', [1930 => 50_000, 8310 => -50_000]);
    Bookkeeping::postRows($test->owner, $test->company, '2026-09-03', [4010 => 6_000_000, 5010 => 3_000_000, 8410 => 30_000, 7832 => 500_000, 1930 => -9_530_000]);
}

it('adds last year’s saved fördelningsbelopp and saves what the owner does not use', function (array $inputs, bool $books, array $expected) {
    if ($books) {
        neExampleOne($this);
    }

    $calc = neSaved($this, inputs: $inputs)->calculation();

    expect($calc['r29'])->toBe($books ? 270_400 : 65_200)
        ->and(array_intersect_key($calc, $expected))->toBe($expected);
})->with([
    'M1a: choice 0 saves it all' => [['capital_base' => '320 000', 'saved_allocation_opening' => '20 000', 'positive_allocation' => '0'], true, ['r30' => 0, 'saved' => 47_360]],
    'M1b: the maximum chosen' => [['capital_base' => '320 000', 'saved_allocation_opening' => '20 000', 'positive_allocation' => '47 360'], true, ['r30' => 47_360, 'saved' => 0]],
]);

it('caps the chosen räntefördelning by R29 and saves the rest of the sparat belopp', function () {
    // R13 ... R28 make R29 = 30 000 from the 65 200 surplus: R16 35 200.
    $calc = neSaved($this, ['7701' => '35 200'], ['capital_base' => '320 000', 'saved_allocation_opening' => '20 000', 'positive_allocation' => '47 360'])->calculation();

    expect($calc['r29'])->toBe(30_000)->and($calc['r30'])->toBe(30_000)->and($calc['saved'])->toBe(17_360);
});

it('sets the saved amount off against a negative räntefördelning first', function (string $base, string $saved, int $r31, int $toSave) {
    // R29 below zero, so nothing can be used for positive räntefördelning.
    $ne = neSaved($this, ['7701' => '70 000'], ['capital_base' => $base, 'saved_allocation_opening' => $saved]);
    $calc = $ne->calculation();

    expect($calc['r29'])->toBeLessThan(0)
        ->and($calc['r31'])->toBe($r31)
        ->and($calc['saved'])->toBe($toSave)
        ->and($ne->ne()[8011] ?? 0)->toBe($toSave);
})->with([
    'M1d: SKV 306 at 8,55 %' => ['50 500', '1 000', 0, 5_317],
    'M1e: the negative one is larger' => ['-600 000', '5 000', 16_300, 0],
    'M1f: the saved amount is larger' => ['-600 000', '30 000', 0, 8_700],
    'M1g: within 500 000, nothing to set off' => ['-400 000', '10 000', 0, 10_000],
]);

it('makes negative räntefördelning at the mandatory amounts', function (string $base, int $r31) {
    $ne = neSaved($this, inputs: ['capital_base' => $base]);

    expect($ne->calculation()['r31'])->toBe($r31);

    if ($r31 > 0) {
        expect($ne->ne())->toMatchArray([7607 => $r31, 8010 => abs((int) str_replace(' ', '', $base))]);
    }
})->with([
    'M2b' => ['-600 000', 21_300],
    'M2c' => ['-500 000', 0],
    'M2d' => ['-500 001', 17_750],
]);

it('warns that negative räntefördelning is mandatory when last year’s books show a large negative underlag', function () {
    foreach ([1930 => 10_000_000, 2350 => -70_000_000] as $number => $ore) {
        OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => Account::query()->where('number', $number)->value('id'), 'amount_ore' => $ore]);
    }

    $ne = neSaved($this);

    expect($ne->estimatedCapitalBase())->toBe(-600_000)
        ->and(implode(' ', $ne->warnings()))->toContain('Negativ räntefördelning är tvingande (inkomstskattelagen 33 kap. 2 §)');

    expect(implode(' ', neSaved($this, inputs: ['capital_base' => '-600 000'])->warnings()))->not->toContain('ser ut att vara lägre än minus 500 000 kr');
});

it('makes no positive räntefördelning from the underlag the first year', function () {
    $ne = neSaved($this, inputs: ['capital_base' => '100 000', 'first_year' => true, 'saved_allocation_opening' => '1 000']);

    expect($ne->calculation())->toMatchArray(['from_base' => 0, 'positive_max' => 1_000])
        ->and(implode(' ', $ne->warnings()))->toContain('Skatteverkets ställningstagande 2026-01-26, dnr 8-16818-2026');
});

it('starts a new NE from the fördelningsbelopp saved on last year’s', function () {
    $last = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    // 2025 has no verified parameters, so only the amount carried in is saved again.
    $this->returns->saveNe($this->owner, $last, [], ['saved_allocation_opening' => '8 550'], '19', null);

    Livewire::test(IncomeTaxPage::class)->assertSet('inputs.saved_allocation_opening', '8550');
});

it('gives 10 % schablon after a full pension, and none to an active owner born 1937 or earlier', function () {
    neExampleOne($this);
    // R42 = 180 325 as in worked example 1 (R16 stands in for its räntefördelning and periodiseringsfond), R40 − R41 = 2 000.
    $calc = neSaved($this, ['7701' => '92 075', '7610' => '50 000', '7713' => '48 000'], ['full_pension' => true])->calculation();

    expect($calc['r42'])->toBe(180_325)->and($calc['r43_rate'])->toBe(1000)->and($calc['r43'])->toBe(18_032);

    $this->company->forceFill(['org_number' => '371231-1230'])->save();
    $ne = neSaved($this);
    expect($ne->ne())->not->toHaveKey(7714)
        ->and(implode(' ', $ne->warnings()))->not->toContain('Fyll i R43 själv');
});

it('caps the expansionsfond at 125,94 % of the closing underlag and proposes the reversals', function (array $adjustments, array $inputs, int $r36, int $toReverse, ?string $warning) {
    $ne = neSaved($this, $adjustments, $inputs);
    $calc = $ne->calculation();

    expect($calc['r36'])->toBe($r36)
        ->and($calc['r37_forced'] + $calc['r37'])->toBe($toReverse === 0 ? $calc['r37'] : $toReverse);

    if ($warning !== null) {
        expect(implode(' ', $ne->warnings()))->toContain($warning);
    }
})->with([
    'S2a: R37 makes room' => [['7710' => '50 000', '7609' => '50 000'], ['expansion_capital_base' => '100 000', 'expansion_fund_opening' => '120 000'], 50_000, 0, null],
    'S2b: above the cap' => [[], ['expansion_capital_base' => '50 000', 'expansion_fund_opening' => '100 000'], 0, 37_030, 'Expansionsfonden blir större än 125,94 % av kapitalunderlaget.'],
    'S2c: no underlag' => [['7710' => '10 000'], [], 0, 0, 'Ökningen av expansionsfonden är större än det som får sättas av'],
    'S2d: underlag 0' => [[], ['expansion_capital_base' => '0', 'expansion_fund_opening' => '80 000'], 0, 80_000, 'Det finns inget positivt kapitalunderlag för expansionsfond'],
]);

it('caps R37 at the opening fund', function () {
    expect(neSaved($this, ['7609' => '90 000'], ['expansion_capital_base' => '100 000', 'expansion_fund_opening' => '20 000'])->calculation()['r37'])->toBe(20_000);
});

it('moves the accounts marked as förmån from R1 to R3, and counts it once', function () {
    Bookkeeping::postRows($this->owner, $this->company, '2026-09-01', [6991 => 1_200_000, 3990 => -1_200_000]);

    $fields = neSaved($this, inputs: ['benefit_accounts' => '3990'])->ne();
    expect($fields)->toMatchArray([7400 => 100_000, 7402 => 12_000, 7440 => 65_200]);

    $fields = neSaved($this)->ne();
    expect($fields)->toMatchArray([7400 => 112_000, 7440 => 65_200])->not->toHaveKey(7402);

    expect(fn () => neSaved($this, inputs: ['benefit_accounts' => '1930']))->toThrow(ValidationException::class);
});

it('warns a company not registered for VAT that sells for more than 120 000 kr', function (int $sales, bool $warned) {
    $this->company->forceFill(['vat_registered' => false, 'vat_period' => VatPeriod::None])->save();
    if ($sales > 100_000) {
        Bookkeeping::postRows($this->owner, $this->company, '2026-09-01', [1930 => ($sales - 100_000) * 100, 3001 => -($sales - 100_000) * 100]);
    }

    expect(str_contains(implode(' ', neSaved($this)->warnings()), 'Företaget är inte momsregistrerat'))->toBe($warned);
})->with([
    '150 000' => [150_000, true],
    '100 000' => [100_000, false],
]);

it('proposes R13 for a periodiseringsfond booked on 8811, so it is not deducted twice', function () {
    Account::create(['number' => 2126, 'name' => 'Periodiseringsfond 2026', 'type' => AccountType::LiabilityEquity]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-12-31', [8811 => 5_000_000, 2126 => -5_000_000]);
    $ne = neSaved($this);

    expect($ne->proposals())->toBe([7601 => 50_000])
        ->and(implode(' ', $ne->warnings()))->toContain('En avsättning till periodiseringsfond är bokförd på 8810–8819');
});

it('puts a net VAT receivable in B8 and an overdrawn bank account in B13', function () {
    Bookkeeping::postRows($this->owner, $this->company, '2026-09-01', [2640 => 20_000_000, 2611 => -7_500_000, 1940 => -12_500_000]);
    $fields = neSaved($this)->ne();

    expect($fields[7260])->toBe(100_000)
        ->and($fields)->not->toHaveKey(7383)
        ->and($fields[7380])->toBe(125_000)
        ->and($fields[7280])->toBe(45_200)
        ->and($fields[7300])->toBe(65_200);
});

<?php

declare(strict_types=1);

/*
 * INK2S 4.6 a, field 7654: schablonintäkt på periodiseringsfonder.
 * IL 30 kap. 6 a § (Lag 2018:1206): statslåneräntan at the end of November
 * the year before the calendar year in which the tax year ends (at least
 * 0,5 %) × the deductions for the funds at the start of the tax year,
 * adjusted in proportion when the tax year is not twelve months.
 * Rates from skatteverket.se (Periodiseringsfond, aktiebolag, read
 * 2026-11-02): 1,96 % end of November 2024, 2,55 % end of November 2025.
 * Every expected figure below is computed by hand (BS-AUD-BOK-8, #83).
 */

use App\Actions\IncomeTax\IncomeTaxReturns;
use App\Domain\Accounting\AccountType;
use App\Domain\IncomeTax\Ink2;
use App\Domain\IncomeTax\TaxAllocationReserves;
use App\Livewire\Accounting\IncomeTaxPage;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Models\OpeningBalance;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['org_number' => '556000-0001', 'postal_code' => '123 45', 'city' => 'Skattstad', 'legal_name' => 'Testbolaget AB'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->returns = app(IncomeTaxReturns::class);

    // Funds from 2022 (60 000) and 2024 (40 000): 100 000 at the start of 2026.
    stdIncomeOpening($this->year, [2122 => -6_000_000, 2124 => -4_000_000, 1930 => 10_000_000]);

    // A profit of 80 000 kr.
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 2_000_000, 5010, 1930, '2026-04-01');
});

/** @param  array<int, int>  $balances  account => öre, debit positive */
function stdIncomeOpening(FiscalYear $year, array $balances): void
{
    foreach ($balances as $number => $ore) {
        $account = Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
        OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => $account->id, 'amount_ore' => $ore]);
    }
}

it('proposes the schablonintäkt from the funds at the start of the year and adds it to 4.15 and 1.1', function () {
    $ink2 = Ink2::for($this->company, $this->year, null);

    // 100 000 × 2,55 % × 12/12 = 2 550; 80 000 + 2 550 = 82 550.
    expect(TaxAllocationReserves::rateFor($this->year))->toBe(255)
        ->and($ink2->proposals())->toBe([7654 => 2_550])
        ->and($ink2->ink2s())->toMatchArray([7650 => 80_000, 7654 => 2_550, 7670 => 82_550])
        ->and($ink2->ink2())->toMatchArray([7104 => 82_550])
        ->and($ink2->taxableKronor())->toBe(82_550)
        ->and($ink2->warnings())->toBe([]);
});

it('uses only the funds at the start of the year, not this year\'s allocation', function () {
    // An allocation during the year (avsättning 2126) does not count until next year.
    foreach ([8811, 2126] as $number) {
        Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
    }

    Bookkeeping::post($this->owner, $this->company, 1_500_000, 8811, 2126, '2026-12-31');

    // Book result 80 000 - 15 000 = 65 000; schablon still 2 550; 65 000 + 2 550 = 67 550.
    expect(Ink2::for($this->company, $this->year, null)->ink2s())->toMatchArray([7650 => 65_000, 7654 => 2_550, 7670 => 67_550]);
});

it('lets a person replace the proposal, or remove it with 0', function () {
    $this->returns->save($this->owner, $this->year, ['7654' => '3 000'], null, null);
    $ink2 = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole());

    expect($ink2->ink2s())->toMatchArray([7654 => 3_000, 7670 => 83_000])
        ->and($ink2->isEntered(7654))->toBeTrue();

    $this->returns->save($this->owner, $this->year, ['7654' => '0'], null, null);
    $ink2 = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole());

    expect($ink2->ink2s())->not->toHaveKey(7654)
        ->and($ink2->taxableKronor())->toBe(80_000);

    // An empty field goes back to the proposal.
    $this->returns->save($this->owner, $this->year, ['7654' => ''], null, null);

    expect(Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole())->ink2s())->toMatchArray([7654 => 2_550, 7670 => 82_550]);
});

it('adjusts the schablonintäkt in proportion for a short tax year', function () {
    // Jul–Dec 2025, six months, ending 2025: rate end of November 2024, 1,96 %.
    $short = FiscalYear::create(['start_date' => '2025-07-01', 'end_date' => '2025-12-31']);
    stdIncomeOpening($short, [2124 => -10_000_000, 1930 => 10_000_000]);

    // 100 000 × 1,96 % × 6/12 = 980.
    expect(TaxAllocationReserves::months($short))->toBe(6)
        ->and(Ink2::for($this->company, $short, null)->proposals())->toBe([7654 => 980])
        ->and(Ink2::for($this->company, $short, null)->ink2s())->toMatchArray([7654 => 980, 7670 => 980]);
});

it('proposes nothing and warns when the rate for the year is not known', function () {
    $later = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    stdIncomeOpening($later, [2126 => -5_000_000, 1930 => 5_000_000]);
    $ink2 = Ink2::for($this->company, $later, null);

    expect($ink2->proposals())->toBe([7654 => null])
        ->and($ink2->ink2s())->not->toHaveKey(7654)
        ->and($ink2->warnings())->toHaveCount(1);

    $this->returns->save($this->owner, $later, ['7654' => '1 250'], null, null);

    expect(Ink2::for($this->company, $later, IncomeTaxReturn::query()->where('fiscal_year_id', $later->id)->sole())->warnings())->toBe([]);
});

it('proposes nothing for a company without funds', function () {
    $later = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

    expect(Ink2::for($this->company, $later, null)->proposals())->toBe([7654 => 0])
        ->and(Ink2::for($this->company, $later, null)->ink2s())->not->toHaveKey(7654)
        ->and(Ink2::for($this->company, $later, null)->warnings())->toBe([]);
});

it('carries 7654 to the SRU file', function () {
    $zipContent = $this->returns->sruZip($this->owner, $this->year);
    $path = (string) tempnam(sys_get_temp_dir(), 'srustd');
    file_put_contents($path, $zipContent);
    $zip = new ZipArchive;
    $zip->open($path);
    $blanketter = (string) $zip->getFromName('BLANKETTER.SRU');
    $zip->close();
    unlink($path);

    expect($blanketter)->toContain("#UPPGIFT 7654 2550\n")
        ->and($blanketter)->toContain("#UPPGIFT 7670 82550\n")
        ->and($blanketter)->toContain("#UPPGIFT 7104 82550\n");
});

it('shows the proposal on the page', function () {
    Livewire::test(IncomeTaxPage::class)
        ->assertSee('Beräknad schablonintäkt på periodiseringsfonder (7654)')
        ->assertSee('Förslag från bokföringen: 2'."\u{a0}".'550'."\u{a0}".'kr', false)
        ->set('adjustments.7654', '0')
        ->call('save')
        ->assertDispatched('toast');

    expect(IncomeTaxReturn::query()->sole()->adjustments)->toBe([7654 => 0]);
});

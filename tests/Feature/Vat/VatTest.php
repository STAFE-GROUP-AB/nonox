<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Vat\SettleVat;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Company\VatPeriod;
use App\Domain\Vat\EskdFile;
use App\Domain\Vat\VatPeriods;
use App\Domain\Vat\VatReturn;
use App\Livewire\Vat\VatReturns;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-02-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::Monthly, 'org_number' => '556334-3689'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
});

/** @param  list<array<string, mixed>>  $rows */
function book(User $user, Company $company, string $date, array $rows): void
{
    app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, ['voucher_date' => $date, 'description' => 'Test', 'rows' => $rows]));
}

/**
 * January at a restaurant: drinks at 25 %, food at 12 %, and a purchase
 * with input VAT. The öre make the rounding visible.
 */
function januaryAtTheRestaurant(User $user, Company $company): void
{
    book($user, $company, '2026-01-10', [['account' => 1930, 'debit_ore' => 125_060], ['account' => 3001, 'credit_ore' => 100_000], ['account' => 2611, 'credit_ore' => 25_060]]);
    book($user, $company, '2026-01-11', [['account' => 1930, 'debit_ore' => 112_000], ['account' => 3002, 'credit_ore' => 100_000], ['account' => 2621, 'credit_ore' => 12_000]]);
    book($user, $company, '2026-01-20', [['account' => 4010, 'debit_ore' => 80_000], ['account' => 2641, 'debit_ore' => 20_000], ['account' => 1930, 'credit_ore' => 100_000]]);
    book($user, $company, '2026-02-01', [['account' => 1930, 'debit_ore' => 12_500], ['account' => 3001, 'credit_ore' => 10_000], ['account' => 2611, 'credit_ore' => 2_500]]);
}

it('splits a fiscal year into VAT periods', function (VatPeriod $period, int $count, string $lastStart) {
    $periods = VatPeriods::of($this->year, $period);

    expect($periods)->toHaveCount($count)
        ->and(end($periods)['start']->toDateString())->toBe($lastStart)
        ->and(end($periods)['end']->toDateString())->toBe('2026-12-31');
})->with([
    'monthly' => [VatPeriod::Monthly, 12, '2026-12-01'],
    'quarterly' => [VatPeriod::Quarterly, 4, '2026-10-01'],
    'yearly' => [VatPeriod::Yearly, 1, '2026-01-01'],
]);

it('calculates the boxes in whole kronor', function () {
    januaryAtTheRestaurant($this->owner, $this->company);

    $return = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));

    expect(array_filter($return->boxes()))->toBe([5 => 2000, 10 => 250, 11 => 120, 48 => 200, 49 => 170])
        ->and($return->sources[5])->toBe([
            ['number' => 3001, 'name' => 'Försäljning inom Sverige, 25 % moms', 'amount' => 100_000],
            ['number' => 3002, 'name' => 'Försäljning inom Sverige, 12 % moms', 'amount' => 100_000],
        ]);
});

it('books the VAT to 2650 with the öre on 3740, and keeps the boxes', function () {
    januaryAtTheRestaurant($this->owner, $this->company);

    $report = app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));
    $voucher = $report->voucher()->with('rows.account')->sole();

    expect($voucher->reference())->toBe('M1')
        ->and($voucher->source)->toBe(VoucherSource::Vat)
        ->and($voucher->voucher_date->toDateString())->toBe('2026-01-31')
        ->and($voucher->description)->toBe('Momsredovisning Januari 2026')
        ->and($voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all())
        ->toBe([2611 => 25_060, 2621 => 12_000, 2641 => -20_000, 2650 => -17_000, 3740 => -60])
        ->and($report->boxes[49])->toBe(170)
        ->and(AuditLog::forCompany($this->company)->where('action', 'vat.settled')->sole()->payload['payable'])->toBe(170);

    // The declaration reads the same after the VAT has been booked.
    expect(VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'))->boxes())
        ->toBe(array_map(intval(...), $report->boxes));
});

it('books a period once, and never an empty one', function () {
    januaryAtTheRestaurant($this->owner, $this->company);
    app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));

    expect(fn () => app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01')))->toThrow(ValidationException::class, 'redan bokförd')
        ->and(fn () => app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-03-01')))->toThrow(ValidationException::class, 'ingen moms')
        ->and(fn () => app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-15')))->toThrow(ValidationException::class, 'finns inte');
});

it('never changes a booked VAT period', function () {
    januaryAtTheRestaurant($this->owner, $this->company);
    $report = app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));

    expect(fn () => $report->delete())->toThrow(LogicException::class);

    $refused = false;
    try {
        DB::transaction(fn () => DB::delete('delete from vat_reports where id = ?', [$report->id]));
    } catch (QueryException $exception) {
        $refused = str_contains($exception->getMessage(), 'cannot be changed or removed');
    }

    expect($refused)->toBeTrue();
});

it('writes the file for Skatteverket', function () {
    januaryAtTheRestaurant($this->owner, $this->company);

    $return = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));

    expect(EskdFile::render($this->company, $return))->toBe(<<<'XML'
        <?xml version="1.0" encoding="ISO-8859-1"?>
        <!DOCTYPE eSKDUpload PUBLIC "-//Skatteverket, Sweden//DTD Skatteverket eSKDUpload-DTD Version 6.0//SV" "https://www1.skatteverket.se/demoeskd/eSKDUpload_6p0.dtd">
        <eSKDUpload Version="6.0">
          <OrgNr>556334-3689</OrgNr>
          <Moms>
            <Period>202601</Period>
            <ForsMomsEjAnnan>2000</ForsMomsEjAnnan>
            <MomsUtgHog>250</MomsUtgHog>
            <MomsUtgMedel>120</MomsUtgMedel>
            <MomsIngAvdr>200</MomsIngAvdr>
            <MomsBetala>170</MomsBetala>
          </Moms>
        </eSKDUpload>

        XML)
        ->and(EskdFile::filename($this->company, $return))->toBe('moms_5563343689_202601.xml');

    $this->get(route('vat.file', ['fiscalYear' => $this->year, 'period' => '2026-01-01']))->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="moms_5563343689_202601.xml"');
});

it('shows the declaration, books the VAT and locks the period with toasts', function () {
    januaryAtTheRestaurant($this->owner, $this->company);

    $this->get(route('vat.index'))->assertOk()->assertSee('Momsdeklaration Januari 2026');

    Livewire::test(VatReturns::class)
        ->assertSee('Moms att betala')
        ->call('settle')
        ->assertDispatched('toast', message: 'Momsen är bokförd på M1. Att betala: 170 kr.')
        ->assertSee('Momsen är bokförd på')
        ->call('lock')
        ->assertDispatched('toast', message: 'Bokföringen är låst till och med 2026-01-31.');

    expect($this->company->fresh()->locked_until_date->toDateString())->toBe('2026-01-31')
        ->and(fn () => book($this->owner, $this->company, '2026-01-25', [['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100]]))
        ->toThrow(ValidationException::class, 'låst');
});

it('warns when something was booked in the period afterwards', function () {
    januaryAtTheRestaurant($this->owner, $this->company);
    app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));
    book($this->owner, $this->company, '2026-01-30', [['account' => 1930, 'debit_ore' => 12_500], ['account' => 3001, 'credit_ore' => 10_000], ['account' => 2611, 'credit_ore' => 2_500]]);

    Livewire::test(VatReturns::class, [])->set('period', '2026-01-01')->assertSee('rutorna nedan skiljer sig');
});

/*
 * A posting in a period whose VAT is already booked (AUD-MOMS-5, #63). ML 7 kap.
 * 14 §: the VAT belongs to the period it is booked in. Skatteverket "Rätta en
 * momsdeklaration" (read 2026-11-02): the whole return for the period is filed
 * again. Enligt granskning 2026-11-02, juristgranskning återstår.
 */
it('books a supplement for VAT posted in a settled period and keeps the corrected return as a new version', function () {
    januaryAtTheRestaurant($this->owner, $this->company);
    $first = app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));
    book($this->owner, $this->company, '2026-01-30', [['account' => 1930, 'debit_ore' => 12_500], ['account' => 3001, 'credit_ore' => 10_000], ['account' => 2611, 'credit_ore' => 2_500]]);

    $report = app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));
    $voucher = $report->voucher()->with('rows.account')->sole();

    // 2611 is now 275,60 kr: box 10 = 275, box 49 = 275 + 120 − 200 = 195 (was 170). The supplement moves the 25 kr to 2650.
    expect($first->version)->toBe(1)
        ->and($report->version)->toBe(2)
        ->and($report->boxes[49])->toBe(195)
        ->and($voucher->reference())->toBe('M2')
        ->and($voucher->source)->toBe(VoucherSource::Vat)
        ->and($voucher->voucher_date->toDateString())->toBe('2026-01-31')
        ->and($voucher->description)->toBe('Kompletterande momsredovisning Januari 2026')
        ->and($voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all())->toBe([2611 => 2_500, 2650 => -2_500])
        ->and(AuditLog::forCompany($this->company)->where('action', 'vat.supplemented')->sole()->payload)->toMatchArray(['payable' => 195, 'previously_payable' => 170, 'version' => 2, 'voucher' => 'M2'])
        ->and(fn () => app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01')))->toThrow(ValidationException::class, 'redan bokförd');

    // January's VAT accounts are back at zero (2611 keeps February's 25 kr, not settled yet); 2650 holds the corrected box 49.
    $balances = DB::table('voucher_rows')->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
        ->where('voucher_rows.company_id', $this->company->id)->whereIn('accounts.number', [2611, 2621, 2641, 2650])
        ->groupBy('accounts.number')->selectRaw('accounts.number, SUM(debit_ore - credit_ore) AS balance')->pluck('balance', 'number')->map(fn ($b) => (int) $b)->all();

    expect($balances)->toEqual([2611 => -2_500, 2621 => 0, 2641 => 0, 2650 => -19_500]);
});

it('puts only the öre on 3740 when a late posting does not change the declared kronor', function () {
    januaryAtTheRestaurant($this->owner, $this->company);
    app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));
    // 0,30 kr more on 2611: 250,90 kr, still 250 in box 10, so box 49 stays 170.
    book($this->owner, $this->company, '2026-01-30', [['account' => 1930, 'debit_ore' => 150], ['account' => 3001, 'credit_ore' => 120], ['account' => 2611, 'credit_ore' => 30]]);

    $report = app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));

    expect($report->boxes[49])->toBe(170)
        ->and($report->voucher()->with('rows.account')->sole()->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all())
        ->toBe([2611 => 30, 3740 => -30]);
});

it('dates the supplement today when the period has been locked since', function () {
    januaryAtTheRestaurant($this->owner, $this->company);
    app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));
    book($this->owner, $this->company, '2026-01-30', [['account' => 1930, 'debit_ore' => 12_500], ['account' => 3001, 'credit_ore' => 10_000], ['account' => 2611, 'credit_ore' => 2_500]]);
    $this->company->forceFill(['locked_until_date' => '2026-01-31'])->save();

    $report = app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));

    expect($report->voucher()->sole()->voucher_date->toDateString())->toBe('2026-02-15')
        ->and($report->period_end->toDateString())->toBe('2026-01-31');

    // The February return does not read the January supplement: VAT settlements are left out.
    expect(VatReturn::calculate($this->year, CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-28'))->vatAccounts)->toBe([2611 => -2_500]);
});

it('offers the supplement on the page and says the return must be corrected at Skatteverket', function () {
    januaryAtTheRestaurant($this->owner, $this->company);
    app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));
    book($this->owner, $this->company, '2026-01-30', [['account' => 1930, 'debit_ore' => 12_500], ['account' => 3001, 'credit_ore' => 10_000], ['account' => 2611, 'credit_ore' => 2_500]]);

    Livewire::test(VatReturns::class)->set('period', '2026-01-01')
        ->assertSee('Bokför tillägget till momsen')
        ->call('settle')
        ->assertDispatched('toast', message: 'Tillägget är bokfört på M2. Lämna en rättad momsdeklaration för perioden hos Skatteverket.')
        ->assertDontSee('Bokför tillägget till momsen')
        ->assertSee('Ett tillägg har bokförts efter en sen bokföring i perioden');
});

it('keeps VAT to those who prepare it', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));

    $this->get(route('vat.index'))->assertForbidden();
    $this->get(route('vat.file', ['fiscalYear' => $this->year, 'period' => '2026-01-01']))->assertForbidden();
});

it('explains when the company is not VAT registered', function () {
    $this->company->forceFill(['vat_period' => VatPeriod::None])->save();

    Livewire::test(VatReturns::class)->assertSee('Företaget är inte momsregistrerat');
});

it('settles only the VAT accounts that feed a box and lists the others (AUD-MOMS-1, #58)', function () {
    // ML 7 kap. 14 och 31 §§: vilande moms is reported when the customer pays or the invoice is paid.
    // Hand computation: 2611 −250,60 kr is declared in box 10 (250), 2641 +200 in box 48 (200), box 49 = 50.
    // 2618 −250 kr and 2648 +100 kr are in no box: the settlement leaves them, so 3740 only takes the 0,60 kr dropped from box 10.
    Account::create(['number' => 2618, 'name' => 'Vilande utgående moms, 25 %', 'type' => 'LIABILITY_EQUITY']);
    Account::create(['number' => 2648, 'name' => 'Vilande ingående moms', 'type' => 'ASSET']);
    book($this->owner, $this->company, '2026-01-10', [['account' => 1510, 'debit_ore' => 125_000], ['account' => 3001, 'credit_ore' => 100_000], ['account' => 2618, 'credit_ore' => 25_000]]);
    book($this->owner, $this->company, '2026-01-11', [['account' => 4010, 'debit_ore' => 40_000], ['account' => 2648, 'debit_ore' => 10_000], ['account' => 2440, 'credit_ore' => 50_000]]);
    book($this->owner, $this->company, '2026-01-12', [['account' => 1930, 'debit_ore' => 125_060], ['account' => 3001, 'credit_ore' => 100_000], ['account' => 2611, 'credit_ore' => 25_060]]);
    book($this->owner, $this->company, '2026-01-13', [['account' => 4010, 'debit_ore' => 80_000], ['account' => 2641, 'debit_ore' => 20_000], ['account' => 1930, 'credit_ore' => 100_000]]);

    $return = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));

    expect($return->boxes()[48])->toBe(200)
        ->and($return->boxes()[49])->toBe(50)
        ->and($return->unsettled)->toBe([
            ['number' => 2618, 'name' => 'Vilande utgående moms, 25 %', 'amount' => -25_000],
            ['number' => 2648, 'name' => 'Vilande ingående moms', 'amount' => 10_000],
        ]);

    Livewire::test(VatReturns::class)->set('period', '2026-01-01')
        ->assertSee('Momskonton som inte ingår i deklarationen')
        ->assertSee('Vilande ingående moms');

    $report = app(SettleVat::class)($this->owner, $this->year, CarbonImmutable::parse('2026-01-01'));

    expect($report->voucher()->with('rows.account')->sole()->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all())
        ->toBe([2611 => 25_060, 2641 => -20_000, 2650 => -5_000, 3740 => -60]);
});

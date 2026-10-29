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

it('keeps VAT to those who prepare it', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));

    $this->get(route('vat.index'))->assertForbidden();
    $this->get(route('vat.file', ['fiscalYear' => $this->year, 'period' => '2026-01-01']))->assertForbidden();
});

it('explains when the company is not VAT registered', function () {
    $this->company->forceFill(['vat_period' => VatPeriod::None])->save();

    Livewire::test(VatReturns::class)->assertSee('Företaget är inte momsregistrerat');
});

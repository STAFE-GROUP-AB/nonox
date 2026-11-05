<?php

declare(strict_types=1);

use App\Actions\Accounting\CarryForwardBalances;
use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Company\VatPeriod;
use App\Domain\Guide\MoneyNotYours;
use App\Domain\Guide\MoneyNotYoursFigures;
use App\Domain\Overview\OverviewCache;
use App\Livewire\Company\CompanyDetailsForm;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

/*
 * Momsbufferten (ADR-051, stage 3): what of the money in the bank is not the
 * company's, read from posted vouchers only.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_registered' => true, 'vat_period' => VatPeriod::Quarterly, 'f_skatt' => true, 'employer_registered' => false])->save();
    CurrentCompany::set($this->company);
});

/**
 * Posts one voucher with the rows given as account => signed öre (debit positive).
 *
 * @param  array<int, int>  $rows
 */
function bufferPost(User $user, Company $company, string $date, array $rows, string $series = 'A'): void
{
    $draft = app(SaveDraftVoucher::class)($user, $company, [
        'voucher_date' => $date,
        'description' => 'Test',
        'series' => $series,
        'rows' => collect($rows)->map(fn (int $amount, int $account): array => ['account' => $account, 'debit_ore' => max($amount, 0), 'credit_ore' => max(-$amount, 0)])->values()->all(),
    ]);
    app(PostVoucher::class)($user, $draft);
}

function bufferFigures(Company $company, string $today = '2026-11-05'): ?MoneyNotYoursFigures
{
    app()->forgetScopedInstances();

    return app(MoneyNotYours::class)->for($company->fresh(), CarbonImmutable::parse($today));
}

it('reads the bank, the VAT not yet declared and the VAT on 2650 from posted vouchers', function () {
    // A sale of 100 000 kr + 25 000 kr VAT, paid to the bank.
    bufferPost($this->owner, $this->company, '2026-10-10', [1930 => 12500000, 3001 => -10000000, 2611 => -2500000]);
    // A purchase of 20 000 kr + 5 000 kr VAT.
    bufferPost($this->owner, $this->company, '2026-10-20', [4010 => 2000000, 2641 => 500000, 1930 => -2500000]);
    // Q3 already settled to 2650 and not paid: 8 000 kr to pay.
    bufferPost($this->owner, $this->company, '2026-09-30', [2611 => 1000000, 2641 => -200000, 2650 => -800000], 'M');
    // Money on a second bank account; a draft and a future voucher count for nothing.
    bufferPost($this->owner, $this->company, '2026-07-01', [1940 => 3000000, 2099 => -3000000]);
    Bookkeeping::draft($this->owner, $this->company, 99900, 1930, 3001, '2026-11-01');
    bufferPost($this->owner, $this->company, '2026-12-01', [1930 => 100000000, 3001 => -100000000]);

    $figures = bufferFigures($this->company);

    expect($figures->bankTotal())->toBe(10000000 + 3000000)
        ->and(collect($figures->bank)->pluck('number')->all())->toBe([1930, 1940])
        ->and($figures->vatUnreportedTotal())->toBe(2500000 - 500000 - 1000000 + 200000)
        ->and($figures->vatReported['amount'])->toBe(800000)
        ->and($figures->vatTotal())->toBe(1200000 + 800000)
        ->and($figures->employer)->toBeNull()
        ->and($figures->taxAccount['amount'])->toBe(0)
        ->and($figures->preliminaryTax)->toBeNull()
        ->and($figures->remaining())->toBe(13000000 - 2000000)
        ->and($figures->lastVoucher?->toDateString())->toBe('2026-10-20');
});

it('shows VAT to get back without counting it as money in the bank', function () {
    bufferPost($this->owner, $this->company, '2026-10-20', [4010 => 4000000, 2641 => 1000000, 1930 => -5000000]);
    bufferPost($this->owner, $this->company, '2026-07-01', [1930 => 8000000, 2099 => -8000000]);

    $figures = bufferFigures($this->company);

    expect($figures->vatTotal())->toBe(-1000000)
        ->and($figures->notYours())->toBe(0)
        ->and($figures->remaining())->toBe(3000000)
        ->and($figures->vatExceedsBank())->toBeFalse();
});

it('counts the employer liabilities only for an employer, and the tax account both ways', function () {
    bufferPost($this->owner, $this->company, '2026-10-25', [7010 => 4000000, 2710 => -1200000, 2731 => -1256800, 1930 => -2800000, 7510 => 1256800]);
    bufferPost($this->owner, $this->company, '2026-07-01', [1930 => 10000000, 2099 => -10000000]);
    // 5 000 kr paid in to the tax account.
    bufferPost($this->owner, $this->company, '2026-10-30', [1630 => 500000, 1930 => -500000]);

    expect(bufferFigures($this->company)->employer)->toBeNull();

    $this->company->forceFill(['employer_registered' => true])->save();
    $figures = bufferFigures($this->company);

    expect(collect($figures->employer)->pluck('number')->all())->toBe([2710, 2731])
        ->and($figures->employerTotal())->toBe(2456800)
        ->and($figures->taxAccount['amount'])->toBe(500000)
        ->and($figures->remaining())->toBe(10000000 - 2800000 - 500000 + 500000 - 2456800);
});

it('leaves out an account the chart does not have, instead of showing 0', function () {
    CompanyScope::bypass(fn () => Account::query()->where('company_id', $this->company->id)->whereIn('number', [1630, 2650, 2710, 2731])->delete());
    $this->company->forceFill(['employer_registered' => true])->save();

    $figures = bufferFigures($this->company);

    expect($figures->taxAccount)->toBeNull()
        ->and($figures->vatReported)->toBeNull()
        ->and($figures->employer)->toBeNull()
        ->and($figures->vatUnreported)->toBe([])
        ->and($figures->bank)->toHaveCount(1)
        ->and($figures->bank[0]['number'])->toBe(1930);

    CompanyScope::bypass(fn () => Account::query()->where('company_id', $this->company->id)->whereBetween('number', [1900, 1999])->delete());
    expect(bufferFigures($this->company)->bank)->toBeNull()->and(bufferFigures($this->company)->remaining())->toBeNull();
});

it('adds the coming preliminary tax up to the next VAT date only when the company has entered its monthly amount', function () {
    bufferPost($this->owner, $this->company, '2026-07-01', [1930 => 10000000, 2099 => -10000000]);

    expect(bufferFigures($this->company)->preliminaryTax)->toBeNull();

    $this->company->forceFill(['preliminary_tax_monthly_ore' => 450000])->save();
    $tax = bufferFigures($this->company)->preliminaryTax;

    // Next VAT date: 12 November (Q3). F-skatt on 12 November is the only payment until then.
    expect($tax)->toBe(['monthly' => 450000, 'count' => 1, 'total' => 450000, 'until' => '2026-11-12'])
        ->and(bufferFigures($this->company)->remaining())->toBe(10000000 - 450000);

    $this->company->forceFill(['f_skatt' => false])->save();
    expect(bufferFigures($this->company)->preliminaryTax)->toBeNull();
});

it('uses the year before when this year has no opening balances yet, and the opening balances when it has', function () {
    bufferPost($this->owner, $this->company, '2026-07-01', [1930 => 10000000, 2099 => -10000000]);
    $next = app(CreateNextFiscalYear::class)($this->owner, $this->company);
    $this->travelTo(CarbonImmutable::parse('2027-01-20 10:00:00'));
    bufferPost($this->owner, $this->company, '2027-01-10', [1930 => -1000000, 4010 => 1000000]);

    // 2027 has no opening balances yet: 2026's closing balance plus 2027's vouchers.
    expect(bufferFigures($this->company, '2027-01-20')->bankTotal())->toBe(9000000);

    app(CarryForwardBalances::class)($this->owner, $next);
    // Carried forward: 2027's opening balances plus its vouchers, the same money, not twice.
    expect(bufferFigures($this->company, '2027-01-20')->bankTotal())->toBe(9000000);
});

it('reads a fixed number of queries, however many vouchers', function () {
    $count = function (): int {
        app()->forgetScopedInstances();
        $company = $this->company->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(MoneyNotYours::class)->for($company, CarbonImmutable::today());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    bufferPost($this->owner, $this->company, '2026-10-10', [1930 => 12500000, 3001 => -10000000, 2611 => -2500000]);
    $few = $count();

    foreach (range(1, 15) as $day) {
        bufferPost($this->owner, $this->company, sprintf('2026-10-%02d', $day), [1930 => 125000, 3001 => -100000, 2611 => -25000]);
        bufferPost($this->owner, $this->company, sprintf('2026-10-%02d', $day), [1940 => 50000, 1930 => -50000]);
    }

    expect($count())->toBe($few)->toBeLessThanOrEqual(6);
});

it('never reads another company', function () {
    bufferPost($this->owner, $this->company, '2026-07-01', [1930 => 10000000, 2099 => -10000000]);
    [$other, $otherCompany] = Bookkeeping::company();
    CurrentCompany::set($otherCompany);
    bufferPost($other, $otherCompany, '2026-07-01', [1930 => 77700000, 2099 => -77700000]);
    bufferPost($other, $otherCompany, '2026-10-10', [1930 => 1250000, 3001 => -1000000, 2611 => -250000]);

    $figures = bufferFigures($this->company);

    expect($figures->bankTotal())->toBe(10000000)->and($figures->vatTotal())->toBe(0);
});

it('shows Pengar som inte är dina on Översikt and Mina datum, with how it is counted and the note', function () {
    bufferPost($this->owner, $this->company, '2026-07-01', [1930 => 10000000, 2099 => -10000000]);
    bufferPost($this->owner, $this->company, '2026-10-10', [1930 => 2500000, 3001 => -2000000, 2611 => -500000]);
    $this->actingAs($this->owner);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('Pengar som inte är dina')
        ->assertSee('Kvar efter moms och skatter')
        ->assertSee('Moms att betala')
        ->assertSee("120\u{a0}000\u{a0}kr")
        ->assertSee('Obokförda fakturor och kvitton syns inte.')
        ->assertSee(route('guide.dates').'#momsbufferten', false);

    $this->get(route('guide.dates'))->assertOk()
        ->assertSee('Pengar som inte är dina')
        ->assertSee("Till momsdeklarationen 12\u{a0}februari")
        ->assertSee('kvartal 4 2026 hittills')
        ->assertSee("ca 5\u{a0}000\u{a0}kr")
        ->assertSee('Så räknar vi')
        ->assertSee('Moms som ännu inte är redovisad')
        ->assertSee('Bygger på det som är bokfört till och med 5 november 2026.')
        ->assertSee('Senast bokförda verifikation: 10 oktober 2026.')
        ->assertSee('Vill du se kommande preliminärskatt här?');
});

it('warns calmly when the VAT to pay is more than the bank, and links the guide article', function () {
    bufferPost($this->owner, $this->company, '2026-10-10', [1510 => 12500000, 3001 => -10000000, 2611 => -2500000]);
    bufferPost($this->owner, $this->company, '2026-07-01', [1930 => 1000000, 2099 => -1000000]);

    $this->actingAs($this->owner)->get(route('guide.dates'))->assertOk()
        ->assertSee('Momsen att betala är mer än pengarna på banken')
        ->assertSee('/foretagsguiden/moms-och-skatt/satt-undan-pengar', false);
});

it('is only for those who may see the reports, and not when the guide is off', function () {
    bufferPost($this->owner, $this->company, '2026-07-01', [1930 => 10000000, 2099 => -10000000]);
    $approver = Bookkeeping::member($this->company, 'approver');

    $this->actingAs($approver)->get(route('dashboard'))->assertOk()->assertDontSee('Pengar som inte är dina');
    $this->actingAs($approver)->get(route('guide.dates'))->assertOk()->assertDontSee('Pengar som inte är dina');

    $this->company->forceFill(['guide_enabled' => false])->save();
    $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->assertDontSee('Pengar som inte är dina');
});

it('keeps Översikt at a fixed number of queries with the card', function () {
    $this->actingAs($this->owner);
    $count = function (): int {
        $this->get(route('dashboard'));
        DB::flushQueryLog();
        DB::enableQueryLog();
        OverviewCache::forget($this->company->id);
        $this->get(route('dashboard'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    bufferPost($this->owner, $this->company, '2026-07-01', [1930 => 10000000, 2099 => -10000000]);
    $short = $count();

    foreach (range(1, 12) as $day) {
        bufferPost($this->owner, $this->company, sprintf('2026-10-%02d', $day), [1930 => 125000, 3001 => -100000, 2611 => -25000]);
    }

    expect($count())->toBe($short);
});

it('lets the company enter its monthly preliminary tax, audited, and shows the coming payments', function () {
    $this->actingAs($this->owner);
    bufferPost($this->owner, $this->company, '2026-07-01', [1930 => 10000000, 2099 => -10000000]);

    Livewire\Livewire::test(CompanyDetailsForm::class, ['company' => $this->company->fresh()])
        ->set('state.preliminary_tax_monthly', 'abc')->call('save')->assertHasErrors('preliminary_tax_monthly')
        ->set('state.preliminary_tax_monthly', '4 500')->call('save')->assertHasNoErrors()
        ->assertSet('state.preliminary_tax_monthly', '4 500,00');

    expect($this->company->fresh()->preliminary_tax_monthly_ore)->toBe(450000)
        ->and(AuditLog::query()->where('action', 'company.updated')->latest('id')->first()->payload['after'])->toHaveKey('preliminary_tax_monthly_ore');

    $this->get(route('guide.dates'))->assertOk()->assertSee("Preliminärskatt, 1 betalning till 12\u{a0}november")->assertSee("−4\u{a0}500\u{a0}kr");

    Livewire\Livewire::test(CompanyDetailsForm::class, ['company' => $this->company->fresh()])
        ->set('state.preliminary_tax_monthly', '')->call('save')->assertHasNoErrors();
    expect($this->company->fresh()->preliminary_tax_monthly_ore)->toBeNull();
});

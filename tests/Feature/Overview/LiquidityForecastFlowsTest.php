<?php

declare(strict_types=1);

use App\Actions\Accounting\BookVoucher;
use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Invoicing\AgreementStatus;
use App\Domain\Overview\Statistics;
use App\Livewire\Overview\StatisticsPanel;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * The explicit flows of the liquidity forecast on Översikt (ADR-027,
 * addendum 2026-11-08): payroll, VAT, the tax account and agreements, and
 * that none of them is counted twice with the average result.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->today = CarbonImmutable::parse('2026-04-10');
});

/** @return array<string, mixed> */
function forecastStats(User $user, Company $company, CarbonImmutable $today): array
{
    return CurrentCompany::run($company, fn () => app(Statistics::class)->for($user, $company, $today));
}

function forecastInvoice(User $user, Company $company, string $date, string $price, int $terms = 30, string $name = 'Nordljus Konsult HB'): Invoice
{
    $customer = app(SaveCustomer::class)($user, $company, ['name' => $name, 'payment_terms_days' => $terms]);

    return app(IssueInvoice::class)($user, app(SaveDraftInvoice::class)($user, $company, [
        'customer_id' => $customer->id,
        'invoice_date' => $date,
        'rows' => [['description' => 'Konsulttimmar', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500]],
    ]));
}

function forecastAgreement(User $user, Company $company, string $next, string $price = '1000', int $terms = 30): RecurringInvoice
{
    $customer = app(SaveCustomer::class)($user, $company, ['name' => 'Kontoret AB', 'email' => 'ekonomi@kontoret.test', 'payment_terms_days' => $terms]);

    return app(SaveRecurringInvoice::class)($user, $company, [
        'customer_id' => $customer->id,
        'name' => 'Städavtal',
        'interval_months' => 1,
        'next_invoice_date' => $next,
        'rows' => [['description' => 'Städning', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500]],
    ]);
}

/** A run for the month taken through the given steps: calculate, approve, pay, post. */
function forecastRun($test, string $period, string $until): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $run = $runs->create($test->owner, $test->company, $period);
    $runs->calculate($test->owner, $run);

    foreach (['approve' => fn () => $runs->approve($test->admin, $run->fresh()), 'pay' => fn () => $runs->markPaid($test->owner, $run->fresh()), 'post' => fn () => $runs->post($test->owner, $run->fresh())] as $step => $action) {
        if ($until === 'calculate') {
            break;
        }

        $action();

        if ($step === $until) {
            break;
        }
    }

    return $run->fresh();
}

function forecastPayroll($test): void
{
    Payroll::setUp($test->company, $test->owner);
    $test->admin = Bookkeeping::member($test->company, 'admin');
    Payroll::employee($test->owner, $test->company);
    app(AddPayrollAccounts::class)($test->owner, $test->company);
}

/** @return array{net: int, taxes: int} the totals of the run's payslips */
function forecastRunTotals(PayrollRun $run): array
{
    $payslips = Payslip::query()->where('payroll_run_id', $run->id)->get();

    return [
        'net' => (int) $payslips->sum(fn (Payslip $payslip) => $payslip->net_ore->ore),
        'taxes' => (int) $payslips->sum(fn (Payslip $payslip) => $payslip->tax_ore->ore + $payslip->employer_contribution_ore->ore),
    ];
}

it('counts open customer invoices with what is left after part payments and partial credits, never credited ones', function () {
    $partlyPaid = forecastInvoice($this->owner, $this->company, '2026-04-01', '1000');
    app(RegisterPayment::class)($this->owner, $partlyPaid, CarbonImmutable::parse('2026-04-05'), Money::ofOre(50_000));

    $credited = forecastInvoice($this->owner, $this->company, '2026-04-01', '2000', 30, 'Krediterad AB');
    app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $credited));

    $partlyCredited = forecastInvoice($this->owner, $this->company, '2026-04-01', '4000', 30, 'Delkrediterad AB');
    $credit = app(CreditInvoice::class)($this->owner, $partlyCredited);
    $credit = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $credit->customer_id, 'invoice_date' => '2026-04-10', 'due_date' => '2026-04-10',
        'rows' => [['description' => 'Kreditering', 'quantity' => '-1', 'price' => '1000', 'vat_rate' => 2500]],
    ], $credit);
    app(IssueInvoice::class)($this->owner, $credit);

    $forecast = forecastStats($this->owner, $this->company, $this->today)['liquidity']['forecast'];

    // Due 1 May: 1 250 − 500 kr left on the first, nothing on the credited one, 5 000 − 1 250 kr on the third.
    expect($partlyPaid->fresh()->status->value)->toBe('ISSUED')
        ->and($credited->fresh()->status->value)->toBe('CREDITED')
        ->and($forecast[1]['inflow'])->toBe(75_000 + 375_000);
});

it('expects the agreements\' coming invoices and their drafts on their due dates, and takes their revenue out of the average', function () {
    $agreement = forecastAgreement($this->owner, $this->company, '2026-03-05');
    $issued = app(IssueInvoice::class)($this->owner, app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::parse('2026-03-05')));
    // The April invoice is made as a draft and not yet issued: still money to come.
    app(RunRecurringInvoice::class)($this->owner, $agreement->fresh(), CarbonImmutable::parse('2026-04-10'));

    $liquidity = forecastStats($this->owner, $this->company, $this->today)['liquidity'];
    $forecast = $liquidity['forecast'];

    // The issued March invoice (due 4 April) is an open invoice; the April draft is due 5 May,
    // the agreement's next invoice 5 May is due 4 June, the one of 5 June only in July.
    expect($issued->total_ore->ore)->toBe(125_000)
        ->and($forecast[0]['inflow'])->toBe(125_000)
        ->and(array_column($forecast, 'recurring'))->toBe([0, 125_000, 125_000])
        // The March revenue came from the agreement, which the forecast counts on its own.
        ->and($liquidity['average_result'])->toBe(0)
        ->and($liquidity['included']['recurring'])->toBeTrue();
});

it('leaves out paused agreements', function () {
    $agreement = forecastAgreement($this->owner, $this->company, '2026-04-20');
    app(RunRecurringInvoice::class)->setStatus($this->owner, $agreement, AgreementStatus::Paused);

    expect(array_column(forecastStats($this->owner, $this->company, $this->today)['liquidity']['forecast'], 'recurring'))->toBe([0, 0, 0]);
});

it('expects the VAT of the quarter on Skatteverket\'s date', function () {
    // Q1 2026: 2 500 kr output VAT, declared and paid by 12 May 2026 (VatDeadlines, quarterly).
    Bookkeeping::postRows($this->owner, $this->company, '2026-02-10', [1930 => 1_250_000, 3001 => -1_000_000, 2611 => -250_000]);

    $liquidity = forecastStats($this->owner, $this->company, $this->today)['liquidity'];

    expect(array_column($liquidity['forecast'], 'vat'))->toBe([0, -250_000, 0])
        ->and($liquidity['included']['vat'])->toBeTrue();
});

it('expects the preliminary tax the company entered and takes the tax cost out of the average', function () {
    $this->company->forceFill(['preliminary_tax_monthly_ore' => 400_000])->save();
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-31', [8910 => 300_000, 2510 => -300_000]);

    $liquidity = forecastStats($this->owner, $this->company->fresh(), $this->today)['liquidity'];

    // 12 May and 12 June (debited preliminary tax, PreliminaryTaxProvider); 13 April is the April one.
    expect(array_column($liquidity['forecast'], 'tax_account'))->toBe([-400_000, -400_000, -400_000])
        ->and($liquidity['average_result'])->toBe(0)
        ->and($liquidity['included']['preliminary_tax'])->toBeTrue();
});

it('lets money on the tax account pay the taxes', function () {
    $this->company->forceFill(['preliminary_tax_monthly_ore' => 400_000])->save();
    Bookkeeping::postRows($this->owner, $this->company, '2026-04-01', [1630 => 600_000, 1930 => -600_000]);

    $forecast = forecastStats($this->owner, $this->company->fresh(), $this->today)['liquidity']['forecast'];

    expect(array_column($forecast, 'tax_account'))->toBe([0, -200_000, -400_000]);
});

it('counts payroll from the runs and the payroll cost only once', function () {
    forecastPayroll($this);
    Bookkeeping::post($this->owner, $this->company, 3_000_000, 1930, 3001, '2026-03-02');

    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    $march = forecastRun($this, '2026-03', 'post');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    $april = forecastRun($this, '2026-04', 'approve');

    $m = forecastRunTotals($march);
    $a = forecastRunTotals($april);
    $liquidity = forecastStats($this->owner, $this->company, $this->today)['liquidity'];
    $forecast = $liquidity['forecast'];

    // The March salary is in the March result, but the average leaves the payroll vouchers out:
    // 30 000 kr revenue over January–March is 10 000 kr a month, not less the salary.
    expect($liquidity['average_result'])->toBe(1_000_000)
        ->and($liquidity['included']['payroll'])->toBeTrue()
        // April: the approved run's net pay on 24 April and March's tax and contributions (on 2710/2730) on 13 April.
        ->and($forecast[0]['payroll'])->toBe(-($a['net'] + $m['taxes']))
        // May: April's taxes on 12 May and an estimated May salary like the latest run; June likewise.
        ->and($forecast[1]['payroll'])->toBe(-($a['taxes'] + $a['net']))
        ->and($forecast[2]['payroll'])->toBe(-($a['taxes'] + $a['net']))
        // The trend for April is 21 of 30 days of the average, with no salary in it.
        ->and($forecast[0]['trend'])->toBe(700_000)
        ->and($forecast[0]['balance'])->toBe($liquidity['today'] - $a['net'] - $m['taxes'] + 700_000);
});

it('leaves the payroll to the average result while the payroll module is off', function () {
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-25', [7010 => 3_000_000, 1930 => -3_000_000]);

    $liquidity = forecastStats($this->owner, $this->company, $this->today)['liquidity'];

    expect($liquidity['included']['payroll'])->toBeFalse()
        ->and(array_column($liquidity['forecast'], 'payroll'))->toBe([0, 0, 0])
        ->and($liquidity['average_result'])->toBe(-1_000_000);
});

it('shows an unbooked run only to those who may see payroll, and an estimate to the others', function () {
    forecastPayroll($this);
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    $march = forecastRun($this, '2026-03', 'post');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    forecastRun($this, '2026-04', 'approve');

    $auditor = Bookkeeping::member($this->company, 'auditor');
    $m = forecastRunTotals($march);
    $forecast = forecastStats($auditor, $this->company, $this->today)['liquidity']['forecast'];

    // The auditor sees the booked March run as the estimate for April, not the approved April run.
    expect($forecast[0]['payroll'])->toBe(-($m['net'] + $m['taxes']));
});

it('shows the parts of the forecast under the chart', function () {
    $this->company->forceFill(['preliminary_tax_monthly_ore' => 400_000])->save();

    Livewire::withoutLazyLoading()->test(StatisticsPanel::class)
        ->assertSee('Avtalsfakturor')
        ->assertSee('Lön och arbetsgivarskatter')
        ->assertSee('Moms')
        ->assertSee('Skattekontot')
        ->assertSee('Snittresultat');
});

it('never counts another company\'s runs, agreements, VAT or invoices', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::run($other, function () use ($otherOwner, $other) {
        $test = (object) ['owner' => $otherOwner, 'company' => $other];
        forecastPayroll($test);
        forecastRun($test, '2026-04', 'approve');
        forecastAgreement($otherOwner, $other, '2026-04-20');
        forecastInvoice($otherOwner, $other, '2026-04-01', '9000');
        Bookkeeping::postRows($otherOwner, $other, '2026-02-10', [1930 => 1_250_000, 3001 => -1_000_000, 2611 => -250_000]);
        $other->forceFill(['preliminary_tax_monthly_ore' => 400_000])->save();
    });

    $liquidity = forecastStats($this->owner, $this->company, $this->today)['liquidity'];

    foreach ($liquidity['forecast'] as $point) {
        expect([$point['inflow'], $point['recurring'], $point['payroll'], $point['vat'], $point['tax_account']])->toBe([0, 0, 0, 0, 0]);
    }
});

it('reads the forecast with the same number of queries for a short and a long history', function () {
    forecastPayroll($this);
    $count = function (): int {
        forecastStats($this->owner, $this->company, $this->today);
        DB::enableQueryLog();
        DB::flushQueryLog();
        forecastStats($this->owner, $this->company, $this->today);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $this->travelTo(CarbonImmutable::parse('2026-01-20 10:00:00'));
    forecastRun($this, '2026-01', 'post');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    forecastAgreement($this->owner, $this->company, '2026-04-20');
    forecastInvoice($this->owner, $this->company, '2026-04-01', '1000');
    $short = $count();

    $this->travelTo(CarbonImmutable::parse('2026-02-20 10:00:00'));
    forecastRun($this, '2026-02', 'post');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    forecastRun($this, '2026-03', 'post');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    forecastRun($this, '2026-04', 'approve');

    foreach (range(1, 4) as $i) {
        forecastAgreement($this->owner, $this->company, "2026-0{$i}-2{$i}");
        forecastInvoice($this->owner, $this->company, "2026-0{$i}-0{$i}", (string) (1000 * $i), 30, "Kund {$i} AB");
        Bookkeeping::postRows($this->owner, $this->company, "2026-0{$i}-1{$i}", [1930 => 1_250_000, 3001 => -1_000_000, 2611 => -250_000]);
    }

    expect($count())->toBe($short);
});

it('keeps pension and other payroll costs the run does not pay in the average', function () {
    forecastPayroll($this);
    Bookkeeping::post($this->owner, $this->company, 3_000_000, 1930, 3001, '2026-03-02');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    $march = forecastRun($this, '2026-03', 'post');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));

    $before = forecastStats($this->owner, $this->company, $this->today)['liquidity'];

    // An EMPLOYER line of the payroll voucher (SalaryVoucher): pension 7410 against 2740, paid to the insurer, not by the run.
    CurrentCompany::run($this->company, fn () => app(BookVoucher::class)->rows($this->owner, $this->company, CarbonImmutable::parse('2026-03-25'), 'Pension', 'L', [
        ['account' => 7410, 'amount' => 900_000], ['account' => 2740, 'amount' => -900_000],
    ], VoucherSource::Payroll, $march));

    $after = forecastStats($this->owner, $this->company, $this->today)['liquidity'];

    // 9 000 kr over three months lowers the average by 3 000 kr a month, as it did before payroll was counted on its own.
    expect($after['average_result'])->toBe($before['average_result'] - 300_000)
        ->and($after['forecast'][2]['balance'])->toBeLessThan($before['forecast'][2]['balance']);
});

it('nets a credited agreement invoice against its credit note in the average', function () {
    $agreement = forecastAgreement($this->owner, $this->company, '2026-03-05');
    $issued = app(IssueInvoice::class)($this->owner, app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::parse('2026-03-05')));
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $issued));
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    app(RunRecurringInvoice::class)->setStatus($this->owner, $agreement->fresh(), AgreementStatus::Paused);

    // The credit note is dated 10 March: the ledger's March revenue is 1 000 − 1 000 kr = 0.
    expect($issued->fresh()->status->value)->toBe('CREDITED');
    expect(forecastStats($this->owner, $this->company, $this->today)['liquidity']['average_result'])->toBe(0);
});

it('counts the VAT on coming agreement invoices when it falls due in the forecast', function () {
    $this->company->forceFill(['vat_period' => 'MONTHLY'])->save();
    forecastAgreement($this->owner, $this->company->fresh(), '2026-04-20', '100000', 30);

    $forecast = forecastStats($this->owner, $this->company->fresh(), $this->today)['liquidity']['forecast'];

    // 20 April: 125 000 kr in on 20 May, its 25 000 kr VAT out on 12 June (the April period). The May invoice's VAT is due in July.
    expect(array_column($forecast, 'recurring'))->toBe([0, 12_500_000, 12_500_000])
        ->and(array_column($forecast, 'vat'))->toBe([0, 0, -2_500_000]);
});

it('counts a missed agreement date once and leaves out old agreement drafts', function () {
    $old = forecastAgreement($this->owner, $this->company, '2026-01-20');
    // A draft made in January and never issued: due 19 February, more than 30 days ago.
    app(RunRecurringInvoice::class)($this->owner, $old, CarbonImmutable::parse('2026-01-20'));

    $forecast = forecastStats($this->owner, $this->company, $this->today)['liquidity']['forecast'];

    // The next date, 20 February, has passed: counted once from today (due 10 May), then 20 May (due 19 June).
    expect(array_column($forecast, 'recurring'))->toBe([0, 125_000, 125_000]);
});

it('nets a partial credit note of an agreement invoice in its month', function () {
    $agreement = forecastAgreement($this->owner, $this->company, '2026-03-05');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    $issued = app(IssueInvoice::class)($this->owner, app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::parse('2026-03-05')));
    $credit = app(CreditInvoice::class)($this->owner, $issued);
    $credit = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $credit->customer_id, 'invoice_date' => '2026-03-20', 'due_date' => '2026-03-20',
        'rows' => [['description' => 'Kreditering', 'quantity' => '-1', 'price' => '400', 'vat_rate' => 2500]],
    ], $credit);
    app(IssueInvoice::class)($this->owner, $credit);
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    app(RunRecurringInvoice::class)->setStatus($this->owner, $agreement->fresh(), AgreementStatus::Paused);

    expect(forecastStats($this->owner, $this->company, $this->today)['liquidity']['average_result'])->toBe(0);
});

it('under kontantmetoden dates the VAT by the expected payment, so issuing a draft changes nothing', function () {
    $this->company->forceFill(['vat_period' => 'MONTHLY', 'accounting_method' => 'KONTANTMETODEN'])->save();
    $agreement = forecastAgreement($this->owner, $this->company->fresh(), '2026-04-10', '100000', 30);
    $draft = app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::parse('2026-04-10'));
    $before = forecastStats($this->owner, $this->company->fresh(), $this->today)['liquidity']['forecast'];

    app(IssueInvoice::class)($this->owner, $draft->fresh());
    $after = forecastStats($this->owner, $this->company->fresh(), $this->today)['liquidity']['forecast'];

    // Paid 10 May, so the VAT belongs to May and is due in July, after the forecast.
    expect(array_column($after, 'vat'))->toBe(array_column($before, 'vat'))
        ->and(array_column($after, 'balance'))->toBe(array_column($before, 'balance'))
        ->and(array_column($after, 'vat'))->toBe([0, 0, 0]);
});

it('under kontantmetoden counts the VAT of open invoices in the period they are expected to be paid', function () {
    $this->company->forceFill(['vat_period' => 'MONTHLY', 'accounting_method' => 'KONTANTMETODEN'])->save();
    $company = $this->company->fresh();
    // 2 000 kr + 500 kr VAT, due 19 April: paid in April, VAT due 12 June.
    forecastInvoice($this->owner, $company, '2026-03-20', '2000');
    // A supplier invoice of 1 250 kr with 250 kr VAT, due 4 April (overdue): paid now, input VAT also in the April return.
    $supplier = app(SaveSupplier::class)($this->owner, $company, ['name' => 'Lokalvärden AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5010]);
    app(RegisterSupplierInvoice::class)($this->owner, app(SaveSupplierInvoice::class)($this->owner, $company, [
        'supplier_id' => $supplier->id, 'invoice_date' => '2026-03-05', 'total' => '1250', 'vat' => '250', 'vat_treatment' => 'DOMESTIC',
        'rows' => [['amount' => '1000', 'vat_rate' => '2500', 'description' => 'Hyra mars']],
    ]));

    $forecast = forecastStats($this->owner, $company, $this->today)['liquidity']['forecast'];

    expect(array_column($forecast, 'vat'))->toBe([0, 0, -25_000]);
});

it('takes särskild löneskatt out of the average when the preliminary tax pays it', function () {
    forecastPayroll($this);
    $this->company->forceFill(['preliminary_tax_monthly_ore' => 400_000])->save();
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-31', [7530 => 300_000, 2514 => -300_000]);

    expect(forecastStats($this->owner, $this->company->fresh(), $this->today)['liquidity']['average_result'])->toBe(0);
});

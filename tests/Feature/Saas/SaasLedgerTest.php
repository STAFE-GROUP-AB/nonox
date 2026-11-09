<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Domain\Invoicing\AgreementStatus;
use App\Modules\Saas\Actions\CloseSaasMonth;
use App\Modules\Saas\Domain\Movement;
use App\Modules\Saas\Models\SaasCorrection;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Models\SaasEstimate;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\Support\SaasLedger;
use App\Modules\Saas\Support\SaasReport;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Saas;

/*
 * The recurring-revenue ledger end to end (ADR-080 build step 1): agreements
 * become versions, versions become months, months close, and later knowledge
 * becomes corrections without touching a closed month. Synthetic data only.
 */

beforeEach(function () {
    Saas::at('2026-01-15');
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Saas::enable($this->company, $this->owner);
});

/** Asserts MRR = opening + correction + fx + every category, for the month and for each of its customers. */
function assertBridge(SaasMonth $month): void
{
    $movements = array_sum(array_map(fn (Movement $movement): int => $month->movement($movement), Movement::cases()));

    expect($month->mrr_ore)->toBe($month->opening_mrr_ore + $month->correction_ore + $month->fx_ore + $movements, "bridge {$month->key()}");

    foreach (SaasCustomerMonth::query()->where('month', $month->key())->get() as $row) {
        expect($row->mrr_ore)->toBe($row->opening_ore + $row->correction_ore + $row->fx_ore + $row->movement_ore, "customer {$row->customer_id} {$month->key()}");
    }

    expect((int) SaasCustomerMonth::query()->where('month', $month->key())->sum('mrr_ore'))->toBe($month->mrr_ore);
}

it('starts the ledger in the month the module is switched on and normalises every interval', function () {
    Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Månad AB'), '299');
    Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Kvartal AB'), '897', 3);
    Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'År AB'), '3000', 12);

    $january = Saas::month('2026-01-01');

    expect($january->mrr_ore)->toBe(84_800)
        ->and($january->arrOre())->toBe(1_017_600)
        ->and($january->customers)->toBe(3)
        ->and($january->new_ore)->toBe(84_800)
        ->and($january->new_customers)->toBe(3)
        ->and($january->arpaOre())->toBe(28_267)
        ->and(SaasMonth::query()->where('month', '<', '2026-01-01')->exists())->toBeFalse();
    assertBridge($january);
});

it('reconciles a month of new, expanded, paused and churned customers to the öre', function () {
    $a = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Alfa AB'), '299');
    $b = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Beta AB'), '897', 3);
    $c = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Gamma AB'), '500');

    Saas::at('2026-02-10');
    Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Delta AB'), '1200', 1, '2026-02-15');
    Saas::reprice($this->owner, $this->company, $a, '599', '2026-02-01');
    Saas::status($this->owner, $b, AgreementStatus::Paused, '2026-02-05');
    Saas::status($this->owner, $c, AgreementStatus::Ended, '2026-02-20');

    $february = Saas::month('2026-02-01');

    expect($february->opening_mrr_ore)->toBe(109_800)
        ->and($february->new_ore)->toBe(120_000)
        ->and($february->expansion_ore)->toBe(30_000)
        ->and($february->pause_ore)->toBe(-29_900)
        ->and($february->churn_ore)->toBe(-50_000)
        ->and($february->mrr_ore)->toBe(179_900)
        ->and($february->churned_customers)->toBe(1)
        ->and($february->opening_customers)->toBe(3);
    assertBridge($february);

    // Every figure drills down to customers that add up to it (SA-R21).
    foreach (array_keys(SaasReport::CATEGORIES) as $category) {
        $sum = SaasReport::drill('2026-02-01', $category)->sum(fn ($row) => SaasReport::amount($row, $category));
        expect($sum)->toBe((int) $february->getAttribute(SaasReport::CATEGORIES[$category]), $category);
    }

    Saas::at('2026-04-02');
    Saas::status($this->owner, $b->fresh(), AgreementStatus::Active, '2026-04-01');

    expect(Saas::month('2026-04-01')->resumed_ore)->toBe(29_900)
        ->and(Saas::month('2026-04-01')->new_ore)->toBe(0);
});

it('churns an annual agreement at the end of the paid year, not when notice is given (F3)', function () {
    Saas::at('2026-02-20');
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Årskund AB'), '12000', 12, '2026-03-01');

    Saas::at('2026-06-10');
    app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $agreement->customer_id, 'name' => $agreement->name, 'interval_months' => 12,
        'next_invoice_date' => '2026-03-01', 'end_date' => '2027-02-28',
        'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => '12000', 'vat_rate' => 2500]],
    ], $agreement);

    expect(Saas::month('2026-06-01')->scheduled_churn_ore)->toBe(100_000)
        ->and(Saas::month('2026-06-01')->churn_ore)->toBe(0)
        ->and(SaasReport::scheduled('2026-06-01')[0]['churn_month'])->toBe('2027-03-01');

    Saas::at('2027-03-15');
    Saas::recompute($this->company);

    expect(Saas::month('2027-02-01')->mrr_ore)->toBe(100_000)
        ->and(Saas::month('2027-03-01')->churn_ore)->toBe(-100_000)
        ->and(Saas::month('2027-03-01')->churned_customers)->toBe(1)
        ->and(SaasMonth::query()->where('month', '<', '2027-03-01')->sum('churn_ore'))->toBe(0);
});

it('does not move MRR when an invoice of the agreement is credited (SA-R11)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Kredit AB'), '1000', 1, '2026-01-15');
    $invoice = app(RunRecurringInvoice::class)($this->owner, $agreement);
    $invoice = app(IssueInvoice::class)($this->owner, $invoice);
    $before = Saas::month('2026-01-01')->mrr_ore;

    $credit = app(CreditInvoice::class)($this->owner, $invoice->fresh());

    if ($credit->isDraft()) {
        app(IssueInvoice::class)($this->owner, $credit);
    }

    Saas::recompute($this->company);

    expect($before)->toBe(100_000)
        ->and(Saas::month('2026-01-01')->mrr_ore)->toBe(100_000)
        ->and(Saas::month('2026-01-01')->contraction_ore)->toBe(0);
});

it('keeps a closed month as reported and books a back-dated change as a correction in the next open month (F5)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Rättelse AB'), '500');
    $close = app(CloseSaasMonth::class);

    foreach (['2026-02-12' => '2026-01', '2026-03-12' => '2026-02', '2026-04-11' => '2026-03'] as $day => $month) {
        Saas::at($day);
        $close($this->owner, $this->company, $month);
    }

    $march = Saas::month('2026-03-01');
    expect($march->isClosed())->toBeTrue()->and($march->mrr_ore)->toBe(50_000);

    // Registered in April: a price cut effective 1 March.
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '400', '2026-03-01');

    $april = Saas::month('2026-04-01');
    $march = $march->fresh();
    $correction = SaasCorrection::query()->sole();

    expect($march->mrr_ore)->toBe(50_000)
        ->and(SaasCustomerMonth::query()->where('month', '2026-03-01')->sole()->mrr_ore)->toBe(50_000)
        ->and($correction->amount_ore)->toBe(-10_000)
        ->and($correction->affects_month->toDateString())->toBe('2026-03-01')
        ->and($correction->booked_month->toDateString())->toBe('2026-04-01')
        ->and($april->opening_mrr_ore)->toBe(50_000)
        ->and($april->correction_ore)->toBe(-10_000)
        ->and($april->mrr_ore)->toBe(40_000)
        ->and(SaasReport::withCorrections(SaasReport::months())['2026-03-01'])->toBe(40_000)
        ->and(SaasReport::withCorrections(SaasReport::months())['2026-02-01'])->toBe(50_000);
    assertBridge($april);

    // A second recompute adds nothing: the correction is already there.
    Saas::recompute($this->company);
    expect(SaasCorrection::query()->count())->toBe(1);

    // Every closed month reproduces from the versions known when it was closed.
    expect(app(SaasLedger::class)->verify($this->company))->toBe([]);
    $this->artisan('saas:verify', ['company' => (string) $this->company->id])->assertSuccessful();

    // Closing April freezes the correction with it; May starts from April as reported.
    Saas::at('2026-05-11');
    $close($this->owner, $this->company, '2026-04');
    Saas::recompute($this->company);

    expect(Saas::month('2026-05-01')->opening_mrr_ore)->toBe(40_000)
        ->and(Saas::month('2026-05-01')->correction_ore)->toBe(0)
        ->and(app(SaasLedger::class)->verify($this->company))->toBe([]);
});

it('spreads a correction over the closed months it changes, booked once', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Tidig AB'), '500');
    $close = app(CloseSaasMonth::class);

    foreach (['2026-02-12' => '2026-01', '2026-03-12' => '2026-02'] as $day => $month) {
        Saas::at($day);
        $close($this->owner, $this->company, $month);
    }

    Saas::at('2026-03-20');
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '450', '2026-01-01');

    expect(SaasCorrection::query()->get(['affects_month', 'amount_ore'])->map(fn ($row) => [$row->affects_month->toDateString(), $row->amount_ore])->all())
        ->toBe([['2026-01-01', -5_000]])
        ->and(Saas::month('2026-03-01')->correction_ore)->toBe(-5_000)
        ->and(Saas::month('2026-03-01')->mrr_ore)->toBe(45_000)
        ->and(SaasReport::withCorrections(SaasReport::months()))->toBe(['2026-01-01' => 45_000, '2026-02-01' => 45_000, '2026-03-01' => 45_000]);
    assertBridge(Saas::month('2026-03-01'));
});

it('closes months in order, only after they have ended, and never twice', function () {
    Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company), '100');
    $close = app(CloseSaasMonth::class);

    expect(fn () => $close($this->owner, $this->company, '2026-01'))->toThrow(ValidationException::class, 'när den är slut');

    Saas::at('2026-03-05');
    expect(fn () => $close($this->owner, $this->company, '2026-02'))->toThrow(ValidationException::class, 'Stäng januari 2026 först');

    $close($this->owner, $this->company, '2026-01');
    expect(fn () => $close($this->owner, $this->company, '2026-01'))->toThrow(ValidationException::class);

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    expect(fn () => $close($bookkeeper, $this->company->fresh(), '2026-02'))->toThrow(AuthorizationException::class);
});

it('estimates months before the module from issued recurring invoices, kept apart from the ledger (O9)', function () {
    // A second company that issued invoices before switching the module on.
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $this->actingAs($owner);
    Saas::at('2026-01-05');
    $agreement = Saas::agreement($owner, $company, Saas::customer($owner, $company, 'Tidigare AB'), '900', 3, '2026-01-05');
    app(IssueInvoice::class)($owner, app(RunRecurringInvoice::class)($owner, $agreement));

    Saas::at('2026-03-10');
    Saas::enable($company, $owner);

    expect(SaasEstimate::query()->orderBy('month')->get()->map(fn ($row) => [$row->month->toDateString(), $row->mrr_ore])->all())
        ->toBe([['2026-01-01', 30_000], ['2026-02-01', 30_000]])
        ->and(SaasMonth::query()->orderBy('month')->value('month')->toDateString())->toBe('2026-03-01')
        ->and(SaasReport::estimates())->toBe(['2026-01-01' => ['mrr_ore' => 30_000, 'customers' => 1], '2026-02-01' => ['mrr_ore' => 30_000, 'customers' => 1]]);
});

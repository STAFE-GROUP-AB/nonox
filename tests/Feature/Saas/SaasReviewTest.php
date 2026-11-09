<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Domain\Invoicing\AgreementStatus;
use App\Modules\Crm\Actions\ConvertToCustomer;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Modules\Saas\Actions\CloseSaasMonth;
use App\Modules\Saas\Domain\ContractStatus;
use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Domain\VersionCause;
use App\Modules\Saas\Jobs\RecomputeSaas;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\Support\ContractVersions;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\Saas;

/*
 * The findings of the cold review of ADR-080 step 1, end to end through the
 * app's own actions. Synthetic data only.
 */

beforeEach(function () {
    Saas::at('2026-01-15');
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Saas::enable($this->company, $this->owner);
});

it('churns a paused agreement that is ended, moving its amount from Paus to Churn (review 1)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Pausad AB'), '1000');

    Saas::at('2026-02-10');
    Saas::status($this->owner, $agreement, AgreementStatus::Paused, '2026-02-10');

    Saas::at('2026-05-10');
    Saas::status($this->owner, $agreement->fresh(), AgreementStatus::Ended);

    $version = SaasContractVersion::query()->orderByDesc('id')->first();
    expect($version->status)->toBe(ContractStatus::Paused)
        ->and($version->cancel_effective_on->toDateString())->toBe('2026-05-10');

    $may = Saas::month('2026-05-01');
    expect(Saas::month('2026-02-01')->pause_ore)->toBe(-100_000)
        ->and($may->churn_ore)->toBe(-100_000)
        ->and($may->pause_ore)->toBe(100_000)
        ->and($may->resumed_ore)->toBe(0)
        ->and($may->mrr_ore)->toBe(0)
        ->and($may->mrr_ore)->toBe($may->opening_mrr_ore + $may->fx_ore + $may->new_ore + $may->reactivation_ore + $may->resumed_ore + $may->expansion_ore + $may->contraction_ore + $may->pause_ore + $may->churn_ore + $may->correction_ore);
});

it('keeps a future price change when a later change with an earlier date is saved (review 3: 100 then 200)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Framtid AB'), '100');

    Saas::at('2026-02-10');
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '200', '2026-06-01');

    // In March an end date is added, effective today: the price must stay 100 until June.
    Saas::at('2026-03-10');
    app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $agreement->customer_id, 'name' => 'Abonnemang', 'interval_months' => 1, 'next_invoice_date' => '2026-04-01', 'end_date' => '2026-12-31',
        'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => '200', 'vat_rate' => 2500]],
    ], $agreement->fresh());

    Saas::at('2026-07-05');
    Saas::recompute($this->company);

    expect(Saas::month('2026-03-01')->mrr_ore)->toBe(10_000)
        ->and(Saas::month('2026-05-01')->mrr_ore)->toBe(10_000)
        ->and(Saas::month('2026-06-01')->mrr_ore)->toBe(20_000)
        ->and(Saas::month('2026-06-01')->expansion_ore)->toBe(10_000)
        ->and(Saas::month('2026-06-01')->scheduled_churn_ore)->toBe(20_000);
});

it('refuses a Gäller från date far in the past or future (review 3)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company), '100');

    expect(fn () => Saas::reprice($this->owner, $this->company, $agreement->fresh(), '150', '2029-01-01'))->toThrow(ValidationException::class, 'högst två år framåt')
        ->and(fn () => Saas::status($this->owner, $agreement->fresh(), AgreementStatus::Paused, '2010-01-01'))->toThrow(ValidationException::class, 'högst tio år tillbaka');
});

it('leaves the agreement register out while CRM is off and lists it under Källor (review 4)', function () {
    Crm::enable($this->company, $this->owner);
    $customer = Crm::customer($this->company, $this->owner, 'Registerkund AB');
    app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => Crm::accountFor($customer)->id, 'title' => 'Support', 'kind' => 'SUPPORT', 'start_date' => '2026-01-01', 'notice_months' => 1, 'value_period' => 'MONTH', 'value' => '500']);

    expect(Saas::month('2026-01-01')->mrr_ore)->toBe(50_000);

    Saas::at('2026-02-10');
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);
    Saas::recompute($this->company);

    expect(Saas::month('2026-02-01')->mrr_ore)->toBe(0)
        ->and(SaasContractVersion::query()->orderByDesc('id')->first()->excluded)->toBe(ExclusionReason::CrmOff);
    $this->get(route('saas.sources'))->assertOk()->assertSee('CRM är avstängt, så avtalsregistret räknas inte');

    Saas::at('2026-03-10');
    Crm::enable($this->company, $this->owner);
    Saas::recompute($this->company);

    expect(Saas::month('2026-03-01')->mrr_ore)->toBe(50_000)
        ->and(Saas::month('2026-01-01')->mrr_ore)->toBe(50_000);
});

it('records the agreements of a prospect that becomes a customer (review 5)', function () {
    Crm::enable($this->company, $this->owner);
    $prospect = Crm::prospect($this->company, $this->owner, 'Blir kund AB');
    app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => $prospect->id, 'title' => 'Abonnemang', 'kind' => 'SUBSCRIPTION', 'start_date' => '2026-01-01', 'notice_months' => 1, 'value_period' => 'MONTH', 'value' => '700']);

    expect(Saas::month('2026-01-01')->mrr_ore)->toBe(0);

    Saas::at('2026-02-05');
    $customer = app(ConvertToCustomer::class)($this->owner, $this->company, $prospect->fresh());

    $latest = SaasContractVersion::query()->where('source_type', SourceType::CrmAgreement->value)->orderByDesc('id')->first();
    expect($latest->customer_id)->toBe($customer->id)
        ->and($latest->excluded)->toBeNull()
        ->and($latest->effective_from->toDateString())->toBe('2026-02-05')
        ->and(Saas::month('2026-02-01')->mrr_ore)->toBe(70_000);
});

it('lists an unlinked register agreement next to an avtalsfakturering of the same customer as possible double counting (review 6)', function () {
    Crm::enable($this->company, $this->owner);
    $customer = Crm::customer($this->company, $this->owner, 'Dubbel AB');
    Saas::agreement($this->owner, $this->company, $customer, '500');
    app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => Crm::accountFor($customer)->id, 'title' => 'Samma sak', 'kind' => 'SUBSCRIPTION', 'start_date' => '2026-01-01', 'notice_months' => 1, 'value_period' => 'MONTH', 'value' => '500']);

    $this->get(route('saas.sources'))->assertOk()->assertSee('Möjlig dubbelräkning')->assertSee('Dubbel AB')->assertSee('Koppla Samma sak');
});

it('keeps no free text in the ledger and shows the texts from the agreement, or a neutral label (review 7)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Text AB'), '300', 1, '2026-01-01', [
        'rows' => [['description' => 'Hemlig radtext', 'quantity' => '1', 'price' => '300', 'vat_rate' => 2500]],
    ]);

    expect(json_encode(SaasContractVersion::query()->get()->toArray()))->not->toContain('Hemlig radtext')
        ->and(json_encode(SaasCustomerMonth::query()->get()->toArray()))->not->toContain('Hemlig radtext')
        ->and((string) DB::scalar('select string_agg(lines::text, \'\') from saas_contract_versions'))->not->toContain('Hemlig');

    $this->get(route('saas.customers.show', ['customer' => $agreement->customer_id]))->assertOk()->assertSee('Hemlig radtext');
    $this->get(route('saas.movements.show', ['month' => '2026-01', 'kategori' => 'ny']))->assertOk()->assertSee('Avtal 1: Abonnemang');

    DB::table('recurring_invoice_rows')->where('recurring_invoice_id', $agreement->id)->delete();
    $this->get(route('saas.customers.show', ['customer' => $agreement->customer_id]))->assertOk()->assertSee('Rad 1')->assertDontSee('Hemlig radtext');
});

it('queues at most one recompute per company until it starts (review 8)', function () {
    $job = new RecomputeSaas($this->company->id);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueFor)->toBeGreaterThan(0)
        ->and($job->uniqueId())->toBe((string) $this->company->id);
});

it('refuses to close a month that uses a carried exchange rate (review 9)', function () {
    // A contract in euro (as Stripe will bring in step 2), and Riksbanken answering without a rate.
    Http::fake(['*' => Http::response([], 200)]);
    SaasContractVersion::create([
        'source_type' => SourceType::StripeSubscription, 'source_id' => 'sub_test', 'customer_id' => Saas::customer($this->owner, $this->company)->id,
        'effective_from' => '2026-01-01', 'recorded_at' => now(), 'cause' => 'IMPORT', 'status' => 'ACTIVE',
        'lines' => [['key' => 'si_1', 'currency' => 'EUR', 'amount_minor' => 10_000]], 'sha256' => str_repeat('c', 64),
    ]);
    Saas::at('2026-02-12');
    Saas::recompute($this->company);

    expect(Saas::month('2026-01-01')->missing_rates)->toBe(['EUR'])
        ->and(fn () => app(CloseSaasMonth::class)($this->owner, $this->company, '2026-01'))->toThrow(ValidationException::class, 'Riksbanken har ingen kurs för EUR');
});

it('counts a customer whose avtalsfakturering adds up to less than zero as 0 and lists it under Källor (review 10)', function () {
    // Avtalsfakturering accepts a negative quantity (a credit row), so an agreement can add up to less than zero.
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Minus AB'), '500', 1, '2026-01-01', [
        'rows' => [['description' => 'Avdrag', 'quantity' => '-1', 'price' => '500', 'vat_rate' => 2500]],
    ]);
    $net = $agreement->net_ore->ore;

    expect($net)->toBe(-50_000)
        ->and(Saas::month('2026-01-01')->mrr_ore)->toBe(0)
        ->and(Saas::month('2026-01-01')->source_issues)->toBeGreaterThanOrEqual(1);
    $this->get(route('saas.sources'))->assertOk()->assertSee('Kunder med negativ återkommande intäkt')->assertSee('Minus AB');
});

it('takes the company\'s ledger lock when it writes a version, held until the change commits, as a close does (review 11)', function () {
    // A second company, never touched in this transaction: no lock on its key before the write.
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $agreement = Saas::agreement($owner, $company, Saas::customer($owner, $company), '100');
    $held = fn (): int => (int) DB::scalar("select count(*) from pg_locks where locktype = 'advisory' and classid = 80080 and objid = ? and pid = pg_backend_pid()", [$company->id]);

    expect($held())->toBe(0);

    app(ContractVersions::class)->recordRecurringInvoice($agreement->fresh(), VersionCause::Backfill, null, $owner->id);

    expect($held())->toBe(1);
});

it('counts a new avtalsfakturering from its first billed period, not from today (review 12)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Senare AB'), '400', 1, '2026-03-01');

    expect(SaasContractVersion::query()->sole()->effective_from->toDateString())->toBe('2026-03-01');

    Saas::at('2026-03-05');
    Saas::recompute($this->company);

    expect(Saas::month('2026-01-01')->mrr_ore)->toBe(0)
        ->and(Saas::month('2026-02-01')->mrr_ore)->toBe(0)
        ->and(Saas::month('2026-03-01')->new_ore)->toBe(40_000)
        ->and($agreement->exists)->toBeTrue();
});

it('tells the operator in Swedish when the person may not close months (review 13)', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    Saas::at('2026-02-12');

    $this->artisan('saas:close-month', ['company' => (string) $this->company->id, 'month' => '2026-01', '--user' => (string) $bookkeeper->id])
        ->expectsOutput('Personen har inte behörighet att stänga månader i Återkommande intäkter.')
        ->assertFailed();

    expect(SaasMonth::query()->where('status', SaasMonth::CLOSED)->count())->toBe(0);
});

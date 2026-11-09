<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Domain\Invoicing\AgreementStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Saas\Actions\CloseSaasMonth;
use App\Modules\Saas\Livewire\MonthsPage;
use App\Modules\Saas\Livewire\OverviewPage;
use App\Modules\Saas\Models\SaasMonth;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Saas;

/*
 * The pages under /aterkommande-intakter (ADR-080 §4): they show the stored
 * ledger in Swedish, drill down, download as CSV, close a month with a
 * confirmation, and run a fixed number of queries whatever the number of
 * customers (AGENTS.md: N+1 is a bug).
 */

/**
 * A company with the module on and $count customers on monthly agreements
 * since January; in February one is new, one up, one paused, one ended.
 *
 * @return array{0: User, 1: Company}
 */
function saasCompanyWith(int $count): array
{
    Saas::at('2026-01-15');
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    test()->actingAs($owner);
    $agreements = [];

    for ($index = 1; $index <= $count; $index++) {
        $agreements[] = Saas::agreement($owner, $company, Saas::customer($owner, $company, "Kund {$index} AB"), (string) (100 * $index), $index % 3 === 0 ? 3 : 1);
    }

    Saas::enable($company, $owner);
    Saas::at('2026-02-12');
    app(CloseSaasMonth::class)($owner, $company, '2026-01');
    Saas::reprice($owner, $company, $agreements[0]->fresh(), '150', '2026-02-01');
    Saas::status($owner, $agreements[1]->fresh(), AgreementStatus::Paused, '2026-02-01');
    Saas::status($owner, $agreements[3]->fresh(), AgreementStatus::Ended, '2026-02-01');
    Saas::agreement($owner, $company, Saas::customer($owner, $company, 'Ny kund AB'), '990', 1, '2026-03-01');
    // A back-dated change to January, which is closed: a correction in February.
    Saas::reprice($owner, $company, $agreements[2]->fresh(), '250', '2026-01-01');
    app(SaveRecurringInvoice::class)($owner, $company, [
        'customer_id' => $agreements[4]->customer_id, 'name' => 'Abonnemang', 'interval_months' => 1, 'next_invoice_date' => '2026-03-01', 'end_date' => '2026-06-30',
        'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => '500', 'vat_rate' => 2500]],
    ], $agreements[4]->fresh());

    return [$owner, $company];
}

/** @return list<string> */
function saasPages(Company $company): array
{
    $customer = Customer::query()->orderBy('id')->firstOrFail();

    return [
        route('saas.overview'),
        route('saas.overview', ['visa' => 'med-rattelser', 'manad' => '2026-01']),
        route('saas.movements'),
        route('saas.movements.show', ['month' => '2026-02', 'kategori' => 'churn']),
        route('saas.movements.show', ['month' => '2026-02', 'kategori' => 'rattelse']),
        route('saas.customers'),
        route('saas.customers.show', ['customer' => $customer->id]),
        route('saas.cohorts', ['visa' => 'intakt']),
        route('saas.scheduled'),
        route('saas.corrections'),
        route('saas.months'),
        route('saas.sources'),
    ];
}

it('shows every page in Swedish with the figures of the ledger', function () {
    [$owner, $company] = saasCompanyWith(5);

    $this->get(route('saas.overview'))->assertOk()
        ->assertSee('Återkommande intäkter')
        ->assertSee('Nyckeltal för styrning, inte bokföring.')
        ->assertSee('ARR (årstakt)')
        ->assertSee('Från förra månadens MRR till denna');

    foreach (saasPages($company) as $url) {
        $this->get($url)->assertOk();
    }

    $this->get(route('saas.movements.show', ['month' => '2026-02', 'kategori' => 'churn']))
        ->assertSee('Kund 4 AB')
        ->assertSee('Stämmer med månadens belopp.');
    $this->get(route('saas.scheduled'))->assertSee('Kund 5 AB')->assertSee('juli 2026');
    $this->get(route('saas.corrections'))->assertSee('Kund 3 AB')->assertSee('januari 2026');
    $this->get(route('saas.movements.show', ['month' => '2099-01']))->assertNotFound();
});

it('shows the empty states before there are agreements', function () {
    Saas::at('2026-01-15');
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $this->actingAs($owner);
    Saas::enable($company, $owner);

    $this->get(route('saas.overview'))->assertOk()->assertSee('Inga återkommande intäkter än');
    $this->get(route('saas.customers'))->assertOk()->assertSee('Inga kunder än');
    $this->get(route('saas.corrections'))->assertOk()->assertSee('Inga rättelser');
    $this->get(route('saas.sources'))->assertOk()->assertSee('Allt räknas med');
});

it('switches between as reported and with corrections without changing the stored month', function () {
    saasCompanyWith(5);
    $reported = Saas::month('2026-01-01')->mrr_ore;

    Livewire::test(OverviewPage::class)
        ->assertSee('Som rapporterat')
        ->set('show', 'med-rattelser')
        ->assertSee('Stängd, med rättelser');

    expect(Saas::month('2026-01-01')->mrr_ore)->toBe($reported);
});

it('closes a month from Månader after a confirmation, with a toast', function () {
    [, $company] = saasCompanyWith(5);
    Saas::at('2026-03-04');
    Saas::recompute($company);

    Livewire::test(MonthsPage::class)
        ->assertSee('Stäng februari 2026')
        ->assertSee('Ja, stäng månaden')
        ->call('close', '2026-02')
        ->assertDispatched('toast');

    expect(Saas::month('2026-02-01')->isClosed())->toBeTrue();
});

it('downloads every list as CSV with the page\'s figures', function () {
    saasCompanyWith(5);

    foreach (['rorelser', 'kunder', 'manad', 'kohorter', 'uppsagda', 'rattelser', 'manader'] as $list) {
        $response = $this->get(route('saas.export', ['list' => $list, 'manad' => '2026-02', 'kategori' => 'churn']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $response->streamedContent();
        expect($body)->toStartWith("\xEF\xBB\xBF");

        if ($list === 'manad') {
            expect($body)->toContain('Kund 4 AB')->toContain('-400,00');
        }
    }
});

it('runs the same number of queries for 5 customers as for 50', function () {
    [, $small] = saasCompanyWith(5);
    $smallCounts = [];

    foreach (saasPages($small) as $index => $url) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get($url)->assertOk();
        $smallCounts[$index] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }

    [, $large] = saasCompanyWith(50);

    foreach (saasPages($large) as $index => $url) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get($url)->assertOk();
        expect(count(DB::getQueryLog()))->toBe($smallCounts[$index], $url);
        DB::disableQueryLog();
    }

    expect(SaasMonth::query()->where('month', '2026-02-01')->value('customers'))->toBeGreaterThan(40);
});

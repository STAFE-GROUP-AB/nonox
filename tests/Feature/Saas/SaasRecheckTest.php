<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Models\AuditLog;
use App\Models\RecurringInvoice;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Agreement;
use App\Modules\ModuleManager;
use App\Modules\Saas\Actions\MarkSeparateAgreements;
use App\Modules\Saas\Domain\ContractStatus;
use App\Modules\Saas\Domain\ContractVersion;
use App\Modules\Saas\Domain\CustomerState;
use App\Modules\Saas\Domain\Movement;
use App\Modules\Saas\Domain\RecurringRevenue;
use App\Modules\Saas\Domain\RevenueLine;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Livewire\SourcesPage;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Models\SaasSeparatePair;
use App\Modules\Saas\Support\SaasReport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\Saas;

/*
 * The re-check of ADR-080 step 1 after the review fixes: changes of date
 * that were lost, a CRM switch-off counted as churn, a partial pause end,
 * a clamped customer, and marking two agreements as separate. Synthetic data.
 */

beforeEach(function () {
    Saas::at('2026-01-15');
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Saas::enable($this->company, $this->owner);
});

/** Saves the agreement with only the next invoice date changed. */
function moveNextInvoiceDate(RecurringInvoice $agreement, string $next): void
{
    app(SaveRecurringInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $agreement->customer_id, 'name' => $agreement->name, 'interval_months' => 1, 'next_invoice_date' => $next,
        'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => '300', 'vat_rate' => 2500]],
    ], $agreement->fresh());
}

it('moves the start of an agreement before its first invoice, later or earlier (re-check A, case a)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Start AB'), '300', 1, '2026-03-01');

    moveNextInvoiceDate($agreement, '2026-05-01');
    Saas::at('2026-06-05');
    Saas::recompute($this->company);

    expect(Saas::month('2026-03-01')->mrr_ore)->toBe(0)
        ->and(Saas::month('2026-04-01')->mrr_ore)->toBe(0)
        ->and(Saas::month('2026-05-01')->new_ore)->toBe(30_000);

    // Earlier again: from February.
    Saas::at('2026-01-20');
    moveNextInvoiceDate($agreement, '2026-02-01');
    Saas::at('2026-06-05');
    Saas::recompute($this->company);

    expect(Saas::month('2026-02-01')->new_ore)->toBe(30_000)
        ->and(Saas::month('2026-05-01')->new_ore)->toBe(0);
});

it('brings a pending price change forward when it is saved again with an earlier Gäller från (re-check A, case b)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Framåt AB'), '100');

    Saas::at('2026-02-10');
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '200', '2026-06-01');
    $versions = SaasContractVersion::query()->count();
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '200', '2026-04-01');

    Saas::at('2026-07-05');
    Saas::recompute($this->company);

    expect(SaasContractVersion::query()->count())->toBe($versions + 1)
        ->and(Saas::month('2026-03-01')->mrr_ore)->toBe(10_000)
        ->and(Saas::month('2026-04-01')->mrr_ore)->toBe(20_000);
});

it('backdates a change that was saved for today, and never writes an exact duplicate (re-check A, case c)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Bakåt AB'), '100');

    Saas::at('2026-03-10');
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '120');
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '120', '2026-02-01');
    $count = SaasContractVersion::query()->count();

    // The same again, and a save with nothing changed: no new version.
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '120', '2026-02-01');
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '120');

    expect(SaasContractVersion::query()->count())->toBe($count)
        ->and(Saas::month('2026-01-01')->mrr_ore)->toBe(10_000)
        ->and(Saas::month('2026-02-01')->mrr_ore)->toBe(12_000)
        ->and(Saas::month('2026-02-01')->expansion_ore)->toBe(2_000);
});

it('books switching CRM off and on as its own bridge row, never as churn or reactivation (re-check B)', function () {
    Crm::enable($this->company, $this->owner);
    $customer = Crm::customer($this->company, $this->owner, 'Registerkund AB');
    app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => Crm::accountFor($customer)->id, 'title' => 'Support', 'kind' => 'SUPPORT', 'start_date' => '2026-01-01', 'notice_months' => 1, 'value_period' => 'MONTH', 'value' => '500']);

    Saas::at('2026-02-10');
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);
    Saas::recompute($this->company);

    $february = Saas::month('2026-02-01');
    expect($february->source_ore)->toBe(-50_000)
        ->and($february->churn_ore)->toBe(0)
        ->and($february->churned_customers)->toBe(0)
        ->and($february->mrr_ore)->toBe($february->opening_mrr_ore + $february->source_ore);

    Saas::at('2026-03-10');
    Crm::enable($this->company, $this->owner);
    Saas::recompute($this->company);

    $march = Saas::month('2026-03-01');
    expect($march->source_ore)->toBe(50_000)
        ->and($march->reactivation_ore)->toBe(0)
        ->and($march->new_ore)->toBe(0)
        ->and($march->mrr_ore)->toBe(50_000);

    $this->get(route('saas.overview', ['manad' => '2026-03']))->assertOk()->assertSee('Källa av eller på');
});

it('shows "Begränsat till 0" in the drill-down when a customer\'s lines add up to less than zero (re-check P3)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Minus AB'), '500');

    Saas::at('2026-02-10');
    Saas::agreement($this->owner, $this->company, $agreement->customer, '500', 1, '2026-02-01', [
        'rows' => [['description' => 'Avdrag', 'quantity' => '-2', 'price' => '500', 'vat_rate' => 2500]],
    ]);

    expect(Saas::month('2026-02-01')->contraction_ore)->toBe(-50_000)
        ->and(Saas::month('2026-02-01')->churned_customers)->toBe(0);
    $this->get(route('saas.movements.show', ['month' => '2026-02', 'kategori' => 'minskning']))->assertOk()
        ->assertSee('Begränsat till 0')
        ->assertSee('Stämmer med månadens belopp.');
});

it('marks a register agreement and an avtalsfakturering as separate, audited and undoable, with saas:manage only (re-check P3)', function () {
    Crm::enable($this->company, $this->owner);
    $customer = Crm::customer($this->company, $this->owner, 'Två avtal AB');
    $recurring = Saas::agreement($this->owner, $this->company, $customer, '500');
    app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => Crm::accountFor($customer)->id, 'title' => 'Konsultstöd', 'kind' => 'SUPPORT', 'start_date' => '2026-01-01', 'notice_months' => 1, 'value_period' => 'MONTH', 'value' => '800']);
    $crm = Agreement::query()->sole();

    expect(SaasReport::possibleDoubles(CarbonImmutable::today()))->toHaveCount(1);

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    expect(fn () => app(MarkSeparateAgreements::class)->mark($bookkeeper, $this->company->fresh(), $crm->id, $recurring->id))->toThrow(AuthorizationException::class);

    Livewire::test(SourcesPage::class)
        ->assertSee('Markera som separata avtal')
        ->call('markSeparate', $crm->id, $recurring->id)
        ->assertDispatched('toast')
        ->assertSee('Markerade som separata avtal');

    $pair = SaasSeparatePair::query()->sole();
    expect(SaasReport::possibleDoubles(CarbonImmutable::today()))->toBe([])
        ->and($pair->getAttributes())->not->toHaveKey('title')
        ->and(AuditLog::query()->where('action', 'saas.agreements_marked_separate')->exists())->toBeTrue();

    Livewire::test(SourcesPage::class)->call('undoSeparate', $pair->id)->assertDispatched('toast');

    expect(SaasSeparatePair::query()->count())->toBe(0)
        ->and(SaasReport::possibleDoubles(CarbonImmutable::today()))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'saas.agreements_unmarked_separate')->exists())->toBeTrue();
});

it('moves a pending change later when it is saved again with a later Gäller från (re-check 2 C)', function () {
    Saas::at('2026-11-05');
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Senare ändring AB'), '100', 1, '2026-11-01');
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '200', '2027-01-01');
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '200', '2027-03-01');

    Saas::at('2027-04-05');
    Saas::recompute($this->company);

    expect(Saas::month('2027-01-01')->mrr_ore)->toBe(10_000)
        ->and(Saas::month('2027-02-01')->mrr_ore)->toBe(10_000)
        ->and(Saas::month('2027-03-01')->mrr_ore)->toBe(20_000);
});

it('moves the start of a new agreement later when its Gäller från is corrected (re-check 2 C, 11-01 to 11-15)', function () {
    Saas::at('2026-10-20');
    $customer = Saas::customer($this->owner, $this->company, 'Rättat datum AB');
    $agreement = Saas::agreement($this->owner, $this->company, $customer, '300', 1, '2026-12-01', ['effective_from' => '2026-11-01']);
    Saas::reprice($this->owner, $this->company, $agreement->fresh(), '300', '2026-11-15');

    Saas::at('2026-12-05');
    Saas::recompute($this->company);

    $inForce = fn (string $date) => RecurringRevenue::applicable(
        SaasContractVersion::query()->orderBy('id')->get()->map(fn ($version) => $version->toDomain())->all(),
        $date,
    );

    expect($inForce('2026-11-10') === null || ! $inForce('2026-11-10')->inForceAt('2026-11-10'))->toBeTrue()
        ->and($inForce('2026-11-30')?->inForceAt('2026-11-30'))->toBeTrue()
        ->and(Saas::month('2026-11-01')->new_ore)->toBe(30_000);
});

it('writes nothing when a note is edited with a Gäller från on an invoiced agreement (re-check 3 F, Created)', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Anteckning AB'), '300', 1, '2026-01-15');
    app(IssueInvoice::class)($this->owner, app(RunRecurringInvoice::class)($this->owner, $agreement->fresh()));
    $count = SaasContractVersion::query()->count();
    $agreement = $agreement->fresh();

    Saas::at('2026-03-10');
    app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $agreement->customer_id, 'name' => $agreement->name, 'interval_months' => 1, 'next_invoice_date' => $agreement->next_invoice_date->toDateString(),
        'notes' => 'Bara en anteckning', 'effective_from' => '2026-03-01',
        'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => '300', 'vat_rate' => 2500]],
    ], $agreement);
    Saas::recompute($this->company);

    expect(SaasContractVersion::query()->count())->toBe($count)
        ->and(Saas::month('2026-01-01')->mrr_ore)->toBe(30_000)
        ->and(Saas::month('2026-02-01')->mrr_ore)->toBe(30_000);
});

it('writes nothing when a note is edited with a Gäller från on a backfilled agreement (re-check 3 F, Backfill)', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $this->actingAs($owner);
    $agreement = Saas::agreement($owner, $company, Saas::customer($owner, $company, 'Inläst AB'), '400');
    Saas::at('2026-02-10');
    Saas::enable($company, $owner);
    $count = SaasContractVersion::query()->count();

    app(SaveRecurringInvoice::class)($owner, $company, [
        'customer_id' => $agreement->customer_id, 'name' => $agreement->name, 'interval_months' => 1, 'next_invoice_date' => '2026-01-01',
        'your_reference' => 'Ny referens', 'effective_from' => '2026-02-05',
        'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => '400', 'vat_rate' => 2500]],
    ], $agreement->fresh());

    expect(SaasContractVersion::query()->count())->toBe($count)
        ->and(Saas::month('2026-02-01')->mrr_ore)->toBe(40_000);
});

it('keeps a clamped customer with contracts in force active from month to month, so a return is an expansion (re-check 3 G)', function () {
    $versions = [
        new ContractVersion(1, SourceType::RecurringInvoice, 'a', 1, '2026-01-01', ContractStatus::Active, [new RevenueLine('l', '', 'SEK', 100_000)]),
        new ContractVersion(2, SourceType::RecurringInvoice, 'b', 1, '2026-02-01', ContractStatus::Active, [new RevenueLine('l', '', 'SEK', -150_000)]),
        new ContractVersion(3, SourceType::RecurringInvoice, 'b', 1, '2026-05-01', ContractStatus::Active, [new RevenueLine('l', '', 'SEK', -20_000)]),
    ];
    $history = (new RecurringRevenue)->history($versions, '2026-05-01');

    expect($history->month('2026-03-01')->customers[1]->state)->toBe(CustomerState::Active)
        ->and($history->month('2026-04-01')->customers[1]->state)->toBe(CustomerState::Active)
        ->and($history->month('2026-05-01')->customers[1]->movement)->toBe(Movement::Expansion)
        ->and($history->month('2026-05-01')->movementOre(Movement::Reactivation))->toBe(0);
});

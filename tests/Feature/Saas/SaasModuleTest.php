<?php

declare(strict_types=1);

use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Domain\Company\LegalForm;
use App\Domain\Invoicing\AgreementStatus;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\ModuleManager;
use App\Modules\Saas\Actions\CloseSaasMonth;
use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Domain\VersionCause;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\SaasSettings;
use App\Modules\Saas\Support\SaasDeadlines;
use App\Modules\Saas\Support\SaasReport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\Saas;

/*
 * The module itself (ADR-080 §1, §3.1, O6, O10): offered to every company and
 * off by default, its two permissions per role, 404 while off, the backfill
 * when it is switched on, the versions the agreement events write, and the
 * closing reminder in Mina datum.
 */

beforeEach(function () {
    Saas::at('2026-01-15');
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

it('is offered to every company form and is off by default', function () {
    $modules = app(ModuleManager::class);

    expect($modules->isEnabled(SaasModule::KEY, $this->company))->toBeFalse();

    foreach ([LegalForm::Ab, LegalForm::Ef, LegalForm::Hb] as $form) {
        $this->company->forceFill(['legal_form' => $form])->save();
        expect($modules->enableCheck(SaasModule::KEY, $this->company->fresh())->allowed())->toBeTrue();
    }

    Livewire::actingAs($this->owner)->test('modules.company-modules-tab', ['company' => $this->company->fresh()])
        ->assertSee('NONOX SaaS');
});

it('answers 404 on every page while the module is off', function () {
    foreach (['/aterkommande-intakter', '/aterkommande-intakter/rorelser', '/aterkommande-intakter/kunder', '/aterkommande-intakter/kohorter', '/aterkommande-intakter/uppsagda', '/aterkommande-intakter/rattelser', '/aterkommande-intakter/manader', '/aterkommande-intakter/kallor', '/aterkommande-intakter/export/rorelser'] as $path) {
        $this->get($path)->assertNotFound();
    }
});

it('gives view to owner, admin, accountant, bookkeeper and auditor, and manage to the first three', function () {
    Saas::enable($this->company, $this->owner);
    $company = $this->company->fresh();

    foreach (['admin' => true, 'accountant' => true, 'bookkeeper' => false, 'auditor' => false] as $role => $manages) {
        $user = Bookkeeping::member($company, $role);
        $fresh = $company->fresh();
        expect($user->hasTeamPermission($fresh, SaasModule::VIEW))->toBeTrue("{$role} view")
            ->and($user->hasTeamPermission($fresh, SaasModule::MANAGE))->toBe($manages, "{$role} manage");
    }

    $approver = Bookkeeping::member($company, 'approver');
    expect($approver->hasTeamPermission($company->fresh(), SaasModule::VIEW))->toBeFalse();
    $this->actingAs($approver)->get('/aterkommande-intakter')->assertForbidden();
});

it('records every active or paused agreement when switched on, once, and fixes the first ledger month', function () {
    $customer = Saas::customer($this->owner, $this->company);
    $active = Saas::agreement($this->owner, $this->company, $customer, '300');
    $paused = Saas::agreement($this->owner, $this->company, $customer, '200');
    Saas::status($this->owner, $paused, AgreementStatus::Paused);
    $ended = Saas::agreement($this->owner, $this->company, $customer, '100');
    Saas::status($this->owner, $ended, AgreementStatus::Ended);

    expect(SaasContractVersion::query()->count())->toBe(0);

    Saas::at('2026-03-04');
    Saas::enable($this->company, $this->owner);

    $versions = SaasContractVersion::query()->orderBy('id')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions->pluck('cause')->all())->toBe([VersionCause::Backfill, VersionCause::Backfill])
        ->and($versions->pluck('source_id')->all())->toBe([$active->id, $paused->id])
        ->and($versions[0]->effective_from->toDateString())->toBe('2026-01-15')
        ->and(SaasSettings::historyFrom($this->company))->toBe('2026-03-01');

    // Off and on again: nothing changed, nothing written; the first month stays.
    app(ModuleManager::class)->disable(SaasModule::KEY, $this->company, $this->owner, confirmed: true);
    Saas::at('2026-05-02');
    Saas::enable($this->company, $this->owner);

    expect(SaasContractVersion::query()->count())->toBe(2)
        ->and(SaasSettings::historyFrom($this->company))->toBe('2026-03-01');
});

it('writes a version for a change that counts, with its Gäller från date, and none for one that does not', function () {
    Saas::enable($this->company, $this->owner);
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company), '300', 3);

    Saas::at('2026-02-03');
    Saas::reprice($this->owner, $this->company, $agreement, '330', '2026-02-15');
    app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $agreement->customer_id, 'name' => 'Nytt namn', 'interval_months' => 3, 'next_invoice_date' => '2026-01-01',
        'notes' => 'Bara en anteckning', 'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => '330', 'vat_rate' => 2500]],
    ], $agreement->fresh());
    Saas::status($this->owner, $agreement->fresh(), AgreementStatus::Paused, '2026-03-01');

    $versions = SaasContractVersion::query()->orderBy('id')->get();

    expect($versions->pluck('cause')->all())->toBe([VersionCause::Created, VersionCause::Changed, VersionCause::Paused])
        ->and($versions[1]->effective_from->toDateString())->toBe('2026-02-15')
        ->and($versions[1]->lines[0]['amount_minor'])->toBe(33_000)
        ->and($versions[1]->lines[0]['interval_count'])->toBe(3)
        ->and($versions[1]->recorded_by)->toBe($this->owner->id)
        ->and($versions[2]->status->value)->toBe('PAUSED')
        ->and(strlen($versions[2]->sha256))->toBe(64);
});

it('writes nothing while the module is off', function () {
    Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company), '300');

    expect(SaasContractVersion::query()->count())->toBe(0);
});

it('counts a CRM agreement unless an avtalsfakturering bills it, and lists what cannot be counted', function () {
    Crm::enable($this->company, $this->owner);
    Saas::enable($this->company, $this->owner);
    $customer = Crm::customer($this->company, $this->owner, 'Avtalskund AB');
    $account = Crm::accountFor($customer);
    $billed = Saas::agreement($this->owner, $this->company, $customer, '500');
    $save = app(SaveAgreement::class);
    $base = ['account_id' => $account->id, 'kind' => 'SUBSCRIPTION', 'start_date' => '2026-01-01', 'notice_months' => 3, 'value_period' => 'YEAR'];

    $save($this->owner, $this->company, [...$base, 'title' => 'Support', 'value' => '12 000']);
    $save($this->owner, $this->company, [...$base, 'title' => 'Fakturerat', 'value' => '6 000', 'recurring_invoice_id' => $billed->id]);
    $save($this->owner, $this->company, [...$base, 'title' => 'Totalt', 'value' => '24 000', 'value_period' => 'TOTAL']);
    $prospect = Crm::prospect($this->company, $this->owner);
    $save($this->owner, $this->company, [...$base, 'account_id' => $prospect->id, 'title' => 'Utan kund', 'value' => '1 200', 'value_period' => 'MONTH']);

    $crm = SaasContractVersion::query()->where('source_type', SourceType::CrmAgreement->value)->orderBy('id')->get();

    expect($crm->pluck('excluded')->all())->toBe([null, ExclusionReason::LinkedRecurringInvoice, ExclusionReason::TotalWithoutDates, ExclusionReason::NoCustomer])
        // 500 kr a month from the avtalsfakturering plus 12 000 kr a year from the register; the billed one not twice.
        ->and(Saas::month('2026-01-01')->mrr_ore)->toBe(150_000)
        // Two that cannot be counted, and the unlinked register agreement next to the avtalsfakturering (review 6).
        ->and(Saas::month('2026-01-01')->source_issues)->toBe(3)
        ->and(array_keys(SaasReport::possibleDoubles(CarbonImmutable::today())))->toBe([$customer->id]);
});

it('reminds in Mina datum on the 10th to close the month before, until it is closed', function () {
    Saas::enable($this->company, $this->owner);
    Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company), '100');
    $provider = app(SaasDeadlines::class);

    $deadlines = $provider->deadlines($this->company, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));
    expect(array_map(fn ($deadline) => [$deadline->key, $deadline->date->toDateString(), $deadline->isRecommendation, $deadline->route], $deadlines))->toBe([
        ['saas-close:2026-01-01', '2026-02-10', true, 'saas.months'],
        ['saas-close:2026-02-01', '2026-03-10', true, 'saas.months'],
    ]);

    Saas::at('2026-02-11');
    app(CloseSaasMonth::class)($this->owner, $this->company, '2026-01');

    expect(array_map(fn ($deadline) => $deadline->key, $provider->deadlines($this->company, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))))
        ->toBe(['saas-close:2026-02-01']);
});

it('shows an agreement ended on a later date as cancelled MRR from today, and churns it on that date', function () {
    Saas::enable($this->company, $this->owner);
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Årsplan AB'), '12000', 12);

    Saas::at('2026-03-10');
    Saas::status($this->owner, $agreement, AgreementStatus::Ended, '2027-01-01');

    expect(Saas::month('2026-03-01')->scheduled_churn_ore)->toBe(100_000)
        ->and(Saas::month('2026-03-01')->churn_ore)->toBe(0);

    Saas::at('2027-01-05');
    Saas::recompute($this->company);

    expect(Saas::month('2026-12-01')->mrr_ore)->toBe(100_000)
        ->and(Saas::month('2027-01-01')->churn_ore)->toBe(-100_000);
});

it('backfills an agreement entered after its first invoices from the earliest of them', function () {
    $agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company, 'Efterhand AB'), '500', 1, '2026-01-02');
    Saas::at('2026-01-20');
    $invoice = app(RunRecurringInvoice::class)($this->owner, $agreement->fresh());

    Saas::enable($this->company, $this->owner);

    expect($invoice->invoice_date->toDateString())->toBe('2026-01-02')
        ->and($agreement->created_at->toDateString())->toBe('2026-01-15')
        ->and(SaasContractVersion::query()->sole()->effective_from->toDateString())->toBe('2026-01-02');
});

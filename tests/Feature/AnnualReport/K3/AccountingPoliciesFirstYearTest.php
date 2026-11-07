<?php

declare(strict_types=1);

use App\Actions\Accounting\ChangeFirstFiscalYear;
use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Domain\AnnualReport\K3Policies;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Models\AuditLog;
use App\Models\CompanyAccountingPolicy;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

/*
 * ADR-059, addendum 2026-11-07, A.2.3: applies_from is always the start of one
 * of the company's fiscal years. When the first year's dates change, its
 * policies move with it, and the policies of a removed automatic year go.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->first = CurrentCompany::run($this->company, fn () => FiscalYear::query()->where('start_date', '2026-01-01')->sole());
});

it('moves the first year\'s policies to its new start, so a later answer is no change of principle', function () {
    app(SetAccountingPolicy::class)($this->owner, $this->company, $this->first->id, K3PolicyKey::Leasing, 'operational_in_legal_entity');

    app(ChangeFirstFiscalYear::class)($this->owner, $this->first, '2026-03-01', '2027-02-28');

    $first = $this->first->fresh();
    $policy = CurrentCompany::run($this->company, fn () => CompanyAccountingPolicy::query()->sole());

    expect($policy->applies_from->toDateString())->toBe('2026-03-01');

    app(SetAccountingPolicy::class)($this->owner, $this->company, $first->id, K3PolicyKey::Leasing, 'classify');

    $refusals = array_map(fn (array $refusal) => [$refusal['key']->value, $refusal['rule']], K3Policies::forYear($first)->refusals());

    expect($refusals)->toBe([['leasing', 'S1-R5']])
        ->and(CurrentCompany::run($this->company, fn () => CompanyAccountingPolicy::query()->count()))->toBe(1);

    $moved = AuditLog::query()->where('action', 'accounting_policy.moved')->sole();

    expect($moved->subject_id)->toBe((string) $policy->id)
        ->and($moved->payload)->toEqual([
            'key' => 'leasing', 'fiscal_year_id' => $first->id, 'from' => '2026-01-01', 'to' => '2026-03-01',
        ]);
});

it('removes the policies of an automatic later year that the change removes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05'));
    $this->artisan('fiscal-years:create-next')->assertSuccessful();
    $automatic = CurrentCompany::run($this->company, fn () => FiscalYear::query()->where('start_date', '2027-01-01')->sole());

    app(SetAccountingPolicy::class)($this->owner, $this->company, $this->first->id, K3PolicyKey::Leasing, 'operational_in_legal_entity');
    $later = app(SetAccountingPolicy::class)($this->owner, $this->company, $automatic->id, K3PolicyKey::Leasing, 'classify');

    app(ChangeFirstFiscalYear::class)($this->owner, $this->first, '2025-07-01', '2026-12-31');

    $rows = CurrentCompany::run($this->company, fn () => CompanyAccountingPolicy::query()->orderBy('applies_from')->get());

    expect($rows->map(fn (CompanyAccountingPolicy $row) => [$row->key->value, $row->value, $row->applies_from->toDateString()])->all())
        ->toBe([['leasing', 'operational_in_legal_entity', '2025-07-01']]);

    $removed = AuditLog::query()->where('action', 'accounting_policy.removed')->sole();

    expect($removed->subject_id)->toBe((string) $later->id)
        ->and($removed->payload)->toEqual([
            'key' => 'leasing', 'fiscal_year_id' => $automatic->id, 'applies_from' => '2027-01-01', 'reason' => 'first_year_changed',
        ]);
});

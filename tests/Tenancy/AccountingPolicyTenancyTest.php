<?php

declare(strict_types=1);

use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Domain\AnnualReport\K3Leasing;
use App\Domain\AnnualReport\K3Policies;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Models\CompanyAccountingPolicy;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for company_accounting_policies (ADR-059, addendum
 * 2026-11-07, A.5): B's policies are invisible to A, and B's fiscal year id
 * answers 404 from A, for writing and for reading.
 */
beforeEach(function () {
    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();

    $this->yearB = CurrentCompany::run($this->b, fn () => FiscalYear::query()->orderBy('start_date')->firstOrFail());
    $this->yearA = CurrentCompany::run($this->a, fn () => FiscalYear::query()->orderBy('start_date')->firstOrFail());

    app(SetAccountingPolicy::class)($this->ownerB, $this->b, $this->yearB->id, K3PolicyKey::Leasing, 'classify');
});

it('keeps another company\'s policies invisible', function () {
    expect(CurrentCompany::run($this->a, fn () => CompanyAccountingPolicy::query()->count()))->toBe(0)
        ->and(CurrentCompany::run($this->b, fn () => CompanyAccountingPolicy::query()->count()))->toBe(1)
        ->and(K3Policies::forYear($this->yearA)->value(K3PolicyKey::Leasing))->toBeNull()
        ->and(K3Policies::forYear($this->yearB)->value(K3PolicyKey::Leasing))->toBe(K3Leasing::Classify);
});

it('answers 404 for another company\'s fiscal year when writing', function () {
    expect(fn () => app(SetAccountingPolicy::class)($this->ownerA, $this->a, $this->yearB->id, K3PolicyKey::Leasing, 'operational_in_legal_entity'))
        ->toThrow(ModelNotFoundException::class)
        ->and(CurrentCompany::run($this->b, fn () => CompanyAccountingPolicy::query()->sole()->value))->toBe('classify')
        ->and(CurrentCompany::run($this->a, fn () => CompanyAccountingPolicy::query()->count()))->toBe(0);
});

it('answers 404 for another company\'s fiscal year when reading', function () {
    expect(fn () => K3Policies::forUser($this->ownerA, $this->a, $this->yearB->id))->toThrow(ModelNotFoundException::class);
});

it('fills company_id from the year, never from input', function () {
    $policy = app(SetAccountingPolicy::class)($this->ownerA, $this->a, $this->yearA->id, K3PolicyKey::Leasing, 'operational_in_legal_entity');

    expect($policy->company_id)->toBe($this->a->id);
});

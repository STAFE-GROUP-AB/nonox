<?php

declare(strict_types=1);

use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Domain\AnnualReport\K3CashFlowStatement;
use App\Domain\AnnualReport\K3FinancialInstruments;
use App\Domain\AnnualReport\K3Leasing;
use App\Domain\AnnualReport\K3Policies;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Models\AnnualReport;
use App\Models\AuditLog;
use App\Models\CompanyAccountingPolicy;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

/*
 * ADR-059, addendum 2026-11-07, A.2.3 and A.4 S1-P3: K3 accounting policies as
 * data. The policy in force for a year is the row with the latest applies_from
 * on or before the year's start; a write is refused from a year whose annual
 * report is adopted; unsupported values are refusals with their rule id.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();

    CurrentCompany::run($this->company, function () {
        $this->y2026 = FiscalYear::query()->where('start_date', '2026-01-01')->sole();
        $this->y2025 = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $this->y2027 = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    });
});

function setPolicy(object $test, FiscalYear $year, K3PolicyKey $key, string $value, ?string $reason = null, $user = null): CompanyAccountingPolicy
{
    return app(SetAccountingPolicy::class)(($user ?? $test->owner)->fresh(), $test->company, $year->id, $key, $value, $reason);
}

function adopt(object $test, FiscalYear $year): void
{
    CurrentCompany::run($test->company, fn () => AnnualReport::query()->create([
        'fiscal_year_id' => $year->id, 'signatories' => [],
        'updated_by' => $test->owner->id, 'adoption_date' => $year->end_date->addMonths(5)->toDateString(),
    ]));
}

it('S1-R6 uses the row with the latest applies_from on or before the year start', function () {
    setPolicy($this, $this->y2025, K3PolicyKey::FinancialInstruments, 'chapter_11');
    setPolicy($this, $this->y2027, K3PolicyKey::FinancialInstruments, 'chapter_12');

    expect(K3Policies::forYear($this->y2025)->value(K3PolicyKey::FinancialInstruments))->toBe(K3FinancialInstruments::Chapter11)
        ->and(K3Policies::forYear($this->y2026)->value(K3PolicyKey::FinancialInstruments))->toBe(K3FinancialInstruments::Chapter11)
        ->and(K3Policies::forYear($this->y2027)->value(K3PolicyKey::FinancialInstruments))->toBe(K3FinancialInstruments::Chapter12);

    $earlier = CurrentCompany::run($this->company, fn () => FiscalYear::create(['start_date' => '2024-01-01', 'end_date' => '2024-12-31']));

    expect(K3Policies::forYear($earlier)->value(K3PolicyKey::FinancialInstruments))->toBeNull();
});

it('reads every policy of a year with one query', function () {
    foreach ([K3PolicyKey::Leasing, K3PolicyKey::Intangibles] as $key) {
        setPolicy($this, $this->y2025, $key, $key->values()[0]);
    }

    DB::enableQueryLog();
    $policies = K3Policies::forYear($this->y2026);
    $policies->value(K3PolicyKey::Leasing);
    $policies->missing();
    $policies->refusals();

    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
});

it('lists the missing policies with a question, and the transition answers only for a transition year', function () {
    setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'operational_in_legal_entity');

    $missing = K3Policies::forYear($this->y2026)->missing();

    expect(array_map(fn (K3PolicyKey $key) => $key->value, $missing))->toBe([
        'financial_instruments', 'intangibles', 'borrowing_costs', 'fixed_price_contracts', 'cash_flow_statement',
    ])
        ->and(array_map(fn (K3PolicyKey $key) => $key->value, K3Policies::forYear($this->y2026)->missing(transition: true)))->toContain(
            'comparatives_restated_at_transition', 'k3_applied_before', 'left_k2_reason',
        );
});

it('S1-R6 S1-R10 S1-R5 lists unsupported values as refusals with the rule id', function () {
    setPolicy($this, $this->y2026, K3PolicyKey::FinancialInstruments, 'chapter_12');
    setPolicy($this, $this->y2026, K3PolicyKey::Intangibles, 'capitalise');
    setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'classify');
    setPolicy($this, $this->y2026, K3PolicyKey::BorrowingCosts, 'capitalise');
    setPolicy($this, $this->y2026, K3PolicyKey::FixedPriceContracts, 'percentage_of_completion');

    $refusals = K3Policies::forYear($this->y2026)->refusals();

    expect(array_map(fn (array $refusal) => [$refusal['key']->value, $refusal['rule']], $refusals))->toBe([
        ['financial_instruments', 'S1-R6'],
        ['intangibles', 'S1-R10'],
        ['leasing', 'S1-R5'],
        ['borrowing_costs', 'K3 25.3'],
    ]);

    foreach ($refusals as $refusal) {
        expect($refusal['message'])->toBeString()->not->toBe('');
    }
});

it('S1-R2 refuses no cash flow statement for a larger company only', function () {
    setPolicy($this, $this->y2026, K3PolicyKey::CashFlowStatement, 'none');

    expect(K3Policies::forYear($this->y2026)->refusals())->toBe([])
        ->and(K3Policies::forYear($this->y2026)->refusals(largerCompany: true))->sequence(
            fn ($refusal) => $refusal->toMatchArray(['key' => K3PolicyKey::CashFlowStatement, 'rule' => 'S1-R2']),
        );

    setPolicy($this, $this->y2026, K3PolicyKey::CashFlowStatement, 'indirect');

    expect(K3Policies::forYear($this->y2026)->value(K3PolicyKey::CashFlowStatement))->toBe(K3CashFlowStatement::Indirect)
        ->and(K3Policies::forYear($this->y2026)->refusals(largerCompany: true))->toBe([]);
});

it('S1-R7.6 allows chapter 35 when K3 was not applied before, or when K2 was left because of BFNAR 2025:2', function () {
    expect(K3Policies::forYear($this->y2026)->chapter35Allowed())->toBeNull();

    setPolicy($this, $this->y2026, K3PolicyKey::K3AppliedBefore, 'no');
    expect(K3Policies::forYear($this->y2026)->chapter35Allowed())->toBeTrue();

    setPolicy($this, $this->y2026, K3PolicyKey::K3AppliedBefore, 'yes');
    expect(K3Policies::forYear($this->y2026)->chapter35Allowed())->toBeNull();

    setPolicy($this, $this->y2026, K3PolicyKey::LeftK2Reason, 'voluntary');
    expect(K3Policies::forYear($this->y2026)->chapter35Allowed())->toBeFalse();

    setPolicy($this, $this->y2026, K3PolicyKey::LeftK2Reason, 'forced_2025_2');
    expect(K3Policies::forYear($this->y2026)->chapter35Allowed())->toBeTrue();
});

it('S1-R5 keeps leasing as one company-wide row per year, with no per-contract value', function () {
    setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'classify');
    setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'operational_in_legal_entity', 'Alla leasingavtal som operationella.');

    expect(CurrentCompany::run($this->company, fn () => CompanyAccountingPolicy::query()->where('key', 'leasing')->count()))->toBe(1)
        ->and(K3Policies::forYear($this->y2026)->value(K3PolicyKey::Leasing))->toBe(K3Leasing::OperationalInLegalEntity)
        ->and(Schema::getColumnListing('company_accounting_policies'))->toEqualCanonicalizing([
            'id', 'company_id', 'key', 'value', 'applies_from', 'set_by', 'reason', 'created_at', 'updated_at',
        ])
        ->and(Schema::hasTable('lease_contracts'))->toBeFalse();

    // The database keeps one row per company, key and year start.
    expect(fn () => DB::transaction(fn () => CurrentCompany::run($this->company, fn () => CompanyAccountingPolicy::query()->create([
        'key' => 'leasing', 'value' => 'classify', 'applies_from' => '2026-01-01', 'set_by' => $this->owner->id,
    ]))))->toThrow(QueryException::class);
});

it('refuses a write from a year whose annual report is adopted, and from any earlier start', function () {
    setPolicy($this, $this->y2025, K3PolicyKey::Leasing, 'operational_in_legal_entity');
    adopt($this, $this->y2025);

    expect(fn () => setPolicy($this, $this->y2025, K3PolicyKey::Leasing, 'classify'))
        ->toThrow(ValidationException::class, 'fastställd');

    // A later year is still open for a decision.
    setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'classify');

    adopt($this, $this->y2027);

    expect(fn () => setPolicy($this, $this->y2026, K3PolicyKey::Intangibles, 'expense'))
        ->toThrow(ValidationException::class, 'fastställd')
        ->and(K3Policies::forYear($this->y2025)->value(K3PolicyKey::Leasing))->toBe(K3Leasing::OperationalInLegalEntity);
});

it('S1-R6 refuses a switch from chapter 12 back to chapter 11', function () {
    setPolicy($this, $this->y2025, K3PolicyKey::FinancialInstruments, 'chapter_12');

    expect(fn () => setPolicy($this, $this->y2026, K3PolicyKey::FinancialInstruments, 'chapter_11'))
        ->toThrow(ValidationException::class, '10.2')
        ->and(fn () => setPolicy($this, $this->y2027, K3PolicyKey::FinancialInstruments, 'chapter_11'))
        ->toThrow(ValidationException::class, '10.2')
        ->and(K3Policies::forYear($this->y2027)->value(K3PolicyKey::FinancialInstruments))->toBe(K3FinancialInstruments::Chapter12);
});

it('S1-R6 refuses a chapter 12 row that would make a later chapter 11 row a switch back', function () {
    setPolicy($this, $this->y2027, K3PolicyKey::FinancialInstruments, 'chapter_11');

    expect(fn () => setPolicy($this, $this->y2026, K3PolicyKey::FinancialInstruments, 'chapter_12'))
        ->toThrow(ValidationException::class, '10.2');

    // 11 → 12 is allowed (K3 11.3).
    setPolicy($this, $this->y2025, K3PolicyKey::FinancialInstruments, 'chapter_11');

    expect(K3Policies::forYear($this->y2026)->value(K3PolicyKey::FinancialInstruments))->toBe(K3FinancialInstruments::Chapter11);
});

it('rejects a value that does not belong to the key', function () {
    expect(fn () => setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'chapter_11'))
        ->toThrow(ValidationException::class)
        ->and(fn () => setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'operational_in_legal_entity', str_repeat('x', 2001)))
        ->toThrow(ValidationException::class);
});

it('lets owner, admin and accountant prepare policies and refuses everyone else', function (string $role, bool $allowed) {
    $user = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
    $attempt = fn () => setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'operational_in_legal_entity', null, $user);

    if ($allowed) {
        expect($attempt()->set_by)->toBe($user->id);

        return;
    }

    expect($attempt)->toThrow(AuthorizationException::class);
})->with([
    ['owner', true],
    ['admin', true],
    ['accountant', true],
    ['bookkeeper', false],
    ['approver', false],
    ['auditor', false],
]);

it('lets the auditor read the policies but not a member without reports:view', function () {
    setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'operational_in_legal_entity');
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $approver = Bookkeeping::member($this->company, 'approver');

    expect(K3Policies::forUser($auditor, $this->company, $this->y2026->id)->value(K3PolicyKey::Leasing))->toBe(K3Leasing::OperationalInLegalEntity)
        ->and(fn () => K3Policies::forUser($approver, $this->company, $this->y2026->id))->toThrow(AuthorizationException::class);
});

it('writes the audit entry with ids and enums, never the reason text', function () {
    setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'classify');
    $policy = setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'operational_in_legal_entity', 'Hemlig motivering om Anna Andersson');

    $entry = AuditLog::query()->where('action', 'accounting_policy.set')->latest('id')->firstOrFail();

    expect($policy->reason)->toBe('Hemlig motivering om Anna Andersson')
        ->and($entry->company_id)->toBe($this->company->id)
        ->and($entry->subject_id)->toBe((string) $policy->id)
        ->and($entry->payload)->toMatchArray([
            'key' => 'leasing', 'before' => 'classify', 'after' => 'operational_in_legal_entity',
            'fiscal_year_id' => $this->y2026->id, 'reason_given' => true,
        ])
        ->and(json_encode($entry->payload))->not->toContain('Hemlig')->not->toContain('Anna');

    // Saving the same answer again writes nothing.
    setPolicy($this, $this->y2026, K3PolicyKey::Leasing, 'operational_in_legal_entity', 'Hemlig motivering om Anna Andersson');

    expect(AuditLog::query()->where('action', 'accounting_policy.set')->count())->toBe(2);
});

<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\AnnualReport\SavePriorYearSizeFigures;
use App\Domain\AnnualReport\K3Eligibility;
use App\Domain\AnnualReport\K3EligibilityAnswers;
use App\Domain\AnnualReport\K3EligibilityQuestion;
use App\Domain\AnnualReport\K3EligibilityStatus;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\AnnualReport;
use App\Models\ClosingCheck;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

/*
 * Bokslut → Regelverk, the domain (ADR-059, addendum 2026-11-07, S1-P11):
 * S1-R1, R1.1 a–i, R1.2 b, R1.3, R1.4 and S1-R4.8 for the fiscal year 2027,
 * measured on 2026 (kept in NONOX) and 2025 (entered by a person).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    $this->year2026 = FiscalYear::query()->whereYear('start_date', 2026)->firstOrFail();
    $this->year2027 = app(CreateNextFiscalYear::class)($this->owner, $this->company);
});

/** The 2025 figures and the 2026 ledger; $big exceeds two of the K2 1.1C thresholds in both years. */
function eligibilityYears(bool $big = false, array $ledger = []): void
{
    app(SavePriorYearSizeFigures::class)(test()->owner, test()->company, [
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'average_employees_fte' => '2',
        'balance_sheet_total' => $big ? '2 000 000' : '500 000',
        'net_turnover' => $big ? '4 000 000' : '500 000',
    ]);

    $rows = $ledger !== [] ? $ledger : [1930 => $big ? 400_000_000 : 50_000_000, 3001 => $big ? -400_000_000 : -50_000_000];
    Bookkeeping::postRows(test()->owner, test()->company, '2026-03-01', $rows);
    AnnualReport::create(['fiscal_year_id' => test()->year2026->id, 'average_employees' => 2, 'dividend_ore' => 0, 'signatories' => [], 'signing_date' => '2027-01-20', 'updated_by' => test()->owner->id]);
}

/** Answers "no" to every K2 1.1A question and to the listing question, plus $extra. */
function eligibilityNoToAll(array $extra = [], array $accounts = [], bool $classified = false): K3EligibilityAnswers
{
    $answers = [];

    foreach ([K3EligibilityQuestion::Listed, K3EligibilityQuestion::PublicCompany, K3EligibilityQuestion::ParentLargerGroup, K3EligibilityQuestion::ParentGroupAccounts, K3EligibilityQuestion::HousingCooperative, K3EligibilityQuestion::ForeignBranch, K3EligibilityQuestion::ShareBasedPayment, K3EligibilityQuestion::CompoundInstruments, K3EligibilityQuestion::CryptoAssets] as $question) {
        $answers[$question->value] = false;
    }

    return new K3EligibilityAnswers(array_merge($answers, $extra), $accounts, $classified);
}

function eligibilityItem(object $result, string $key): array
{
    return collect($result->items)->firstWhere('key', $key) ?? throw new RuntimeException("No item {$key}");
}

it('S1-R1 allows K2 for a mindre AB the 1.1C exception covers, without asking about 1.1B', function () {
    eligibilityYears();

    $result = K3Eligibility::forYear($this->year2027, eligibilityNoToAll());

    expect($result->status)->toBe(K3EligibilityStatus::K2Allowed)
        ->and($result->exception?->applies)->toBeTrue()
        ->and(eligibilityItem($result, 'k2_1_1b_a')['state'])->toBe('not_needed')
        ->and(eligibilityItem($result, 'k2_1_1b_b')['state'])->toBe('not_needed')
        ->and($result->asks(K3EligibilityQuestion::DeferredTax))->toBeFalse();
});

it('S1-R1.1 a, d and e follow from the legal form of an aktiebolag; a and e are asked of an ekonomisk förening the other way round', function () {
    eligibilityYears();

    $ab = K3Eligibility::forYear($this->year2027, eligibilityNoToAll());

    expect(eligibilityItem($ab, 'k2_1_1a_d'))->toMatchArray(['source' => 'data', 'state' => 'not'])
        ->and(eligibilityItem($ab, 'k2_1_1a_e'))->toMatchArray(['source' => 'data', 'state' => 'not'])
        ->and(eligibilityItem($ab, 'k2_1_1a_a')['question'])->toBe(K3EligibilityQuestion::PublicCompany);

    $this->company->forceFill(['legal_form' => LegalForm::EkonomiskForening])->save();
    $forening = K3Eligibility::forYear($this->year2027, eligibilityNoToAll());

    expect(eligibilityItem($forening, 'k2_1_1a_a'))->toMatchArray(['source' => 'data', 'state' => 'not'])
        ->and(eligibilityItem($forening, 'k2_1_1a_e')['question'])->toBe(K3EligibilityQuestion::HousingCooperative);
});

it('S1-R1.1 a yes to any K2 1.1A question requires K3', function (K3EligibilityQuestion $question) {
    eligibilityYears();

    if ($question === K3EligibilityQuestion::HousingCooperative) {
        $this->company->forceFill(['legal_form' => LegalForm::EkonomiskForening])->save();
    }

    $result = K3Eligibility::forYear($this->year2027, eligibilityNoToAll([$question->value => true]));

    expect($result->status)->toBe(K3EligibilityStatus::K3Required)
        ->and($result->reasons[0])->toContain($question->rule());
})->with([
    'a publikt' => K3EligibilityQuestion::PublicCompany,
    'b större koncern' => K3EligibilityQuestion::ParentLargerGroup,
    'c koncernredovisning' => K3EligibilityQuestion::ParentGroupAccounts,
    'e bostadsrättsförening' => K3EligibilityQuestion::HousingCooperative,
    'f filial' => K3EligibilityQuestion::ForeignBranch,
    'g aktierelaterade ersättningar' => K3EligibilityQuestion::ShareBasedPayment,
    'h konvertibler' => K3EligibilityQuestion::CompoundInstruments,
    'i kryptotillgångar' => K3EligibilityQuestion::CryptoAssets,
]);

it('S1-R1.1 g–i are asked, never computed: unanswered they leave the year undetermined', function () {
    eligibilityYears();
    $answers = eligibilityNoToAll();
    $partial = new K3EligibilityAnswers(array_diff_key($answers->answers, array_flip(['k2_1_1a_g', 'k2_1_1a_h', 'k2_1_1a_i'])));

    $result = K3Eligibility::forYear($this->year2027, $partial);

    expect($result->status)->toBe(K3EligibilityStatus::Undetermined)
        ->and(collect(['k2_1_1a_g', 'k2_1_1a_h', 'k2_1_1a_i'])->every(fn (string $key): bool => eligibilityItem($result, $key)['source'] === 'missing'))->toBeTrue()
        ->and(implode(' ', $result->missing))->toContain('K2 1.1A g')->toContain('K2 1.1A i');
});

it('S1-R1 a större företag must use K3 whatever the answers', function () {
    eligibilityYears(ledger: [1930 => 9_000_000_000, 3001 => -9_000_000_000]);
    app(SavePriorYearSizeFigures::class)($this->owner, $this->company, [
        'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'average_employees_fte' => '60',
        'balance_sheet_total' => '90 000 000', 'net_turnover' => '90 000 000',
    ]);

    $result = K3Eligibility::forYear($this->year2027, eligibilityNoToAll());

    expect($result->status)->toBe(K3EligibilityStatus::K3Required)
        ->and($result->reasons[0])->toContain('Större företag');
});

it('S1-R1.3 without the first exception, 1.1B and the second exception are asked; the previous year\'s K2 comes from NONOX', function () {
    eligibilityYears(big: true);

    $result = K3Eligibility::forYear($this->year2027, eligibilityNoToAll());

    expect($result->exception?->applies)->toBeFalse()
        ->and(eligibilityItem($result, 'k2_1_1c_prev'))->toMatchArray(['source' => 'data', 'state' => 'applies'])
        ->and($result->asks(K3EligibilityQuestion::NormallyNotCovered))->toBeTrue()
        ->and($result->asks(K3EligibilityQuestion::DeferredTax))->toBeTrue()
        ->and($result->status)->toBe(K3EligibilityStatus::Undetermined);
});

it('S1-R1.2 b the 75 % building test uses only the person\'s classification of the revenue accounts', function () {
    eligibilityYears(big: true, ledger: [1930 => 400_000_000, 3004 => -300_000_000, 3001 => -100_000_000]);

    $unclassified = K3Eligibility::forYear($this->year2027, eligibilityNoToAll(['k2_1_1b_a' => false]));

    expect(eligibilityItem($unclassified, 'k2_1_1b_b')['source'])->toBe('missing')
        ->and($unclassified->building['applies'])->toBeNull()
        ->and($unclassified->status)->toBe(K3EligibilityStatus::Undetermined);

    $atThreshold = K3Eligibility::forYear($this->year2027, eligibilityNoToAll(['k2_1_1b_a' => false, 'k2_1_1c_normally' => false], [3004], true));

    expect($atThreshold->building)->toMatchArray(['net_sales_ore' => 400_000_000, 'building_ore' => 300_000_000, 'applies' => true, 'year' => '2026'])
        ->and($atThreshold->status)->toBe(K3EligibilityStatus::K3Required)
        ->and($atThreshold->reasons[0])->toContain('1.1B b');

    $none = K3Eligibility::forYear($this->year2027, eligibilityNoToAll(['k2_1_1b_a' => false], [], true));

    expect($none->building['applies'])->toBeFalse()
        ->and($none->status)->toBe(K3EligibilityStatus::K2Allowed);
});

it('S1-R1.2 b just under 75 % is not caught', function () {
    eligibilityYears(big: true, ledger: [1930 => 400_000_000, 3004 => -299_999_999, 3001 => -100_000_001]);

    $result = K3Eligibility::forYear($this->year2027, eligibilityNoToAll(['k2_1_1b_a' => false], [3004], true));

    expect($result->building['applies'])->toBeFalse();
});

it('S1-R1.3 the second exception lifts 1.1B for a K2 company that normally is not covered (H3)', function () {
    eligibilityYears(big: true);

    $caught = K3Eligibility::forYear($this->year2027, eligibilityNoToAll(['k2_1_1b_a' => true, 'k2_1_1c_normally' => false]));
    $lifted = K3Eligibility::forYear($this->year2027, eligibilityNoToAll(['k2_1_1b_a' => true, 'k2_1_1c_normally' => true]));

    expect($caught->status)->toBe(K3EligibilityStatus::K3Required)
        ->and($lifted->status)->toBe(K3EligibilityStatus::K2Allowed)
        ->and(K3EligibilityQuestion::NormallyNotCovered->question())->toContain('”Med normalt avses mer än något eller några enstaka år.”');
});

it('S1-R1.3 the second exception is not available when the previous year was K3', function () {
    eligibilityYears(big: true);
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    $result = K3Eligibility::forYear($this->year2027, eligibilityNoToAll(['k2_1_1b_a' => true]));

    expect(eligibilityItem($result, 'k2_1_1c_prev')['state'])->toBe('not')
        ->and($result->asks(K3EligibilityQuestion::NormallyNotCovered))->toBeFalse()
        ->and($result->status)->toBe(K3EligibilityStatus::K3Required);
});

it('S1-R4.8 and H2 show the deferred tax as information with the K2 1.1B a text and never answer for the customer', function () {
    eligibilityYears(big: true);

    $result = K3Eligibility::forYear($this->year2027, eligibilityNoToAll(['k2_1_1c_normally' => false], [], true));

    expect($result->deferredTaxInfo)->toContain('uppskjuten skatteskuld')->toContain('obeskattade reserver')->toContain('föreslår aldrig')
        ->and(eligibilityItem($result, 'k2_1_1b_a'))->toMatchArray(['source' => 'missing', 'state' => 'unknown'])
        ->and($result->status)->toBe(K3EligibilityStatus::Undetermined);
});

it('S1-R1.2 b is asked of the person when the assessment year is not kept in NONOX', function () {
    eligibilityYears(big: true);
    AnnualReport::query()->update(['signing_date' => null]);

    $result = K3Eligibility::forYear($this->year2027, eligibilityNoToAll(['k2_1_1b_a' => false]));

    expect($result->building)->toBeNull()
        ->and($result->asks(K3EligibilityQuestion::BuildingRevenue))->toBeTrue();
});

it('S1-R1.4 does not judge a year that begins before 2026 under the 2025:2 wording', function () {
    $year2025 = FiscalYear::query()->forceCreate(['company_id' => $this->company->id, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => 'OPEN']);

    $result = K3Eligibility::forYear($year2025, eligibilityNoToAll());

    expect($result->status)->toBe(K3EligibilityStatus::NotAssessed)
        ->and($result->summary)->toContain('BFNAR 2025:2');
});

it('S1-R9.1 a newly started company is mindre and within the 1.1C exception only with the person\'s confirmation of its first year', function () {
    $result = K3Eligibility::forYear($this->year2027, eligibilityNoToAll([K3EligibilityQuestion::FirstYear->value => true]));

    expect($result->size?->class->value)->toBe('mindre')
        ->and($result->exception?->applies)->toBeTrue()
        ->and($result->asks(K3EligibilityQuestion::FirstYear))->toBeTrue();

    $unconfirmed = K3Eligibility::forYear($this->year2027, eligibilityNoToAll());

    expect($unconfirmed->size?->class->value)->toBe('undetermined')
        ->and($unconfirmed->missingSizeYear)->toBe(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
});

it('S1-R1 is not assessed for a legal form without an annual report under K2 1.1–1.1C in NONOX', function () {
    $this->company->forceFill(['legal_form' => LegalForm::Hb])->save();

    expect(K3Eligibility::forYear($this->year2027)->status)->toBe(K3EligibilityStatus::NotAssessed);
});

it('reads the saved answers from closing_checks', function () {
    ClosingCheck::query()->create(['fiscal_year_id' => $this->year2027->id, 'key' => 'k2_1_1a_g.yes', 'checked_by' => $this->owner->id]);
    ClosingCheck::query()->create(['fiscal_year_id' => $this->year2027->id, 'key' => 'k2_1_1b_b.classified', 'checked_by' => $this->owner->id]);
    ClosingCheck::query()->create(['fiscal_year_id' => $this->year2027->id, 'key' => 'k2_1_1b_b.account.3004', 'checked_by' => $this->owner->id]);
    ClosingCheck::query()->create(['fiscal_year_id' => $this->year2027->id, 'key' => 'cash', 'checked_by' => $this->owner->id]);

    $answers = K3EligibilityAnswers::forYear($this->year2027);

    expect($answers->answers)->toBe(['k2_1_1a_g' => true])
        ->and($answers->buildingAccounts)->toBe([3004])
        ->and($answers->buildingClassified)->toBeTrue()
        ->and($answers->keys())->toBe(['k2_1_1a_g.yes', 'k2_1_1b_b.account.3004', 'k2_1_1b_b.classified']);
});

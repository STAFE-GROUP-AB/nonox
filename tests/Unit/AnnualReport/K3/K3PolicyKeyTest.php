<?php

declare(strict_types=1);

use App\Domain\AnnualReport\K3BorrowingCosts;
use App\Domain\AnnualReport\K3CashFlowStatement;
use App\Domain\AnnualReport\K3FinancialInstruments;
use App\Domain\AnnualReport\K3FixedPriceContracts;
use App\Domain\AnnualReport\K3Intangibles;
use App\Domain\AnnualReport\K3Leasing;
use App\Domain\AnnualReport\K3LeftK2Reason;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\AnnualReport\K3YesNo;

/*
 * ADR-059, addendum 2026-11-07, A.2.3: the K3 accounting policies are data, one
 * backed enum per key. The `components` key waits for human decision H4 and is
 * not a key yet.
 */

it('S1-R5 S1-R6 S1-R10 S1-R2 S1-R7.1 S1-R7.6 has exactly the keys and values of A.2.3', function () {
    $values = [];

    foreach (K3PolicyKey::cases() as $key) {
        $values[$key->value] = $key->values();
    }

    expect($values)->toBe([
        'financial_instruments' => ['chapter_11', 'chapter_12'],
        'intangibles' => ['expense', 'capitalise'],
        'leasing' => ['operational_in_legal_entity', 'classify'],
        'borrowing_costs' => ['expense', 'capitalise'],
        'fixed_price_contracts' => ['completed_contract_23_31', 'percentage_of_completion', 'none'],
        'cash_flow_statement' => ['none', 'indirect'],
        'comparatives_restated_at_transition' => ['yes', 'no'],
        'k3_applied_before' => ['yes', 'no'],
        'left_k2_reason' => ['forced_2025_2', 'voluntary'],
    ])
        ->and(K3PolicyKey::tryFrom('components'))->toBeNull();
});

it('S1-R6 offers chapter 11 only and refuses chapter 12 with the rule id', function () {
    expect(K3FinancialInstruments::Chapter11->refusal())->toBeNull()
        ->and(K3FinancialInstruments::Chapter12->refusal())->not->toBeNull()
        ->and(K3PolicyKey::FinancialInstruments->rule())->toBe('S1-R6');
});

it('S1-R10 refuses the capitalisation model for internally generated intangibles', function () {
    expect(K3Intangibles::Expense->refusal())->toBeNull()
        ->and(K3Intangibles::Capitalise->refusal())->not->toBeNull()
        ->and(K3PolicyKey::Intangibles->rule())->toBe('S1-R10');
});

it('S1-R5 supports operating leases in the legal entity only', function () {
    expect(K3Leasing::OperationalInLegalEntity->refusal())->toBeNull()
        ->and(K3Leasing::Classify->refusal())->not->toBeNull()
        ->and(K3PolicyKey::Leasing->rule())->toBe('S1-R5');
});

it('S1-R2 supports both cash flow choices for a mindre company', function () {
    expect(K3CashFlowStatement::None->refusal())->toBeNull()
        ->and(K3CashFlowStatement::Indirect->refusal())->toBeNull()
        ->and(K3PolicyKey::CashFlowStatement->rule())->toBe('S1-R2');
});

it('S1-R7.1 S1-R7.6 support every transition answer', function () {
    foreach ([...K3YesNo::cases(), ...K3LeftK2Reason::cases(), ...K3FixedPriceContracts::cases()] as $value) {
        expect($value->refusal())->toBeNull();
    }

    expect(K3PolicyKey::ComparativesRestatedAtTransition->rule())->toBe('S1-R7.1')
        ->and(K3PolicyKey::K3AppliedBefore->rule())->toBe('S1-R7.6')
        ->and(K3PolicyKey::LeftK2Reason->rule())->toBe('S1-R7.6')
        ->and(K3PolicyKey::ComparativesRestatedAtTransition->transitionOnly())->toBeTrue()
        ->and(K3PolicyKey::Leasing->transitionOnly())->toBeFalse();
});

it('refuses capitalised borrowing costs (K3 25.3)', function () {
    expect(K3BorrowingCosts::Expense->refusal())->toBeNull()
        ->and(K3BorrowingCosts::Capitalise->refusal())->not->toBeNull()
        ->and(K3PolicyKey::BorrowingCosts->rule())->toBe('K3 25.3');
});

it('turns a stored string into the key\'s value enum and rejects anything else', function () {
    expect(K3PolicyKey::Leasing->valueOf('classify'))->toBe(K3Leasing::Classify)
        ->and(K3PolicyKey::Leasing->valueOf('chapter_11'))->toBeNull()
        ->and(K3PolicyKey::CashFlowStatement->valueOf('direct'))->toBeNull();
});

it('uses the K3 chapter 25 word låneutgifter', function () {
    expect(K3PolicyKey::BorrowingCosts->label())->toBe('Låneutgifter')
        ->and(K3PolicyKey::BorrowingCosts->question())->toContain('låneutgifterna')
        ->and(K3BorrowingCosts::Capitalise->refusal())->toContain('låneutgifter')->not->toContain('lånekostnad');
});

it('states the scope of K3 23.31 in the fixed-price question', function () {
    expect(K3PolicyKey::FixedPriceContracts->question())
        ->toContain('23.31')
        ->toContain('17 kap. 23 §')
        ->toContain('17 kap. 27 §')
        ->toContain('juridisk person');
});

it('has a Swedish label and question for every key', function () {
    foreach (K3PolicyKey::cases() as $key) {
        expect($key->label())->not->toBe('')
            ->and($key->question())->toContain('?');

        // The fixed-price question ends with the scope of K3 23.31 after its question mark.
        if ($key !== K3PolicyKey::FixedPriceContracts) {
            expect($key->question())->toEndWith('?');
        }
    }
});

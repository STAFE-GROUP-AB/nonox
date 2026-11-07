<?php

declare(strict_types=1);

use App\Domain\AnnualReport\K3Rules;
use Carbon\CarbonImmutable;

/*
 * The K3 rules are data (ADR-059, addendum 2026-11-07, A.2.2): every row
 * carries its source, the quote, a status and the date it was read, and the
 * row in force is chosen by the start of the fiscal year.
 */

it('S1-R9 every data row carries a source URL, a quote, a status, a rule id and the date read', function () {
    $entries = K3Rules::entries();

    expect($entries)->not->toBeEmpty();

    foreach ($entries as $key => $rows) {
        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            expect($row['source_url'])->toStartWith('https://', $key)
                ->and(mb_strlen($row['source_quote']))->toBeGreaterThan(20, $key)
                ->and($row['source_ref'])->not->toBe('', $key)
                ->and($row['status'])->toBe('researched', $key)
                ->and($row['read'])->toMatch('/^\d{4}-\d{2}-\d{2}$/', $key)
                ->and($row['rule_id'])->toStartWith('S1-R', $key)
                ->and(array_key_exists('value', $row))->toBeTrue($key);
        }
    }
});

it('S1-R9 holds the thresholds for a större företag from ÅRL 1 kap. 3 §', function () {
    $start = CarbonImmutable::parse('2026-01-01');

    expect(K3Rules::int('larger_company.employees_gt', $start))->toBe(50)
        ->and(K3Rules::int('larger_company.balance_sheet_total_gt_ore', $start))->toBe(4_000_000_000)
        ->and(K3Rules::int('larger_company.net_turnover_gt_ore', $start))->toBe(8_000_000_000)
        ->and(K3Rules::int('larger_company.criteria_needed', $start))->toBe(2)
        ->and(K3Rules::int('larger_company.prior_years', $start))->toBe(2)
        ->and(K3Rules::int('normal_annual_hours', $start))->toBe(1920);
});

it('S1-R1.3 has the K2 1.1C thresholds for years that begin after 2025-12-31 only', function () {
    $start = CarbonImmutable::parse('2026-01-01');

    expect(K3Rules::int('k2_1_1c.employees_gt', $start))->toBe(3)
        ->and(K3Rules::int('k2_1_1c.balance_sheet_total_gt_ore', $start))->toBe(150_000_000)
        ->and(K3Rules::int('k2_1_1c.net_turnover_gt_ore', $start))->toBe(300_000_000)
        ->and(K3Rules::int('k2_1_1c.max_criteria_met', $start))->toBe(1)
        ->and(K3Rules::entry('k2_1_1c.employees_gt', CarbonImmutable::parse('2025-01-01')))->toBeNull();
});

it('S1-R1.4 picks the K3 version by the start of the fiscal year', function () {
    expect(K3Rules::rule('k3_version_by_fiscal_year', CarbonImmutable::parse('2025-01-01'))['value'])->toBe('kons2024')
        ->and(K3Rules::rule('k3_version_by_fiscal_year', CarbonImmutable::parse('2025-12-31'))['value'])->toBe('kons2024')
        ->and(K3Rules::rule('k3_version_by_fiscal_year', CarbonImmutable::parse('2026-01-01'))['value'])->toBe('kons20251215')
        ->and(K3Rules::entry('k3_version_by_fiscal_year', CarbonImmutable::parse('2024-06-01')))->toBeNull();
});

it('S1-R1.4 applies the new wording to an extended first year that begins after 2025-06-30 and ends 2026-12-31 or later', function () {
    $start = CarbonImmutable::parse('2025-08-01');

    expect(K3Rules::rule('k3_version_by_fiscal_year', $start, CarbonImmutable::parse('2026-12-31'), firstYear: true)['value'])->toBe('kons20251215')
        ->and(K3Rules::int('k2_1_1c.employees_gt', $start, CarbonImmutable::parse('2026-12-31'), firstYear: true))->toBe(3)
        // Not the company's first year, or the year ends too early: the old wording.
        ->and(K3Rules::rule('k3_version_by_fiscal_year', $start, CarbonImmutable::parse('2026-12-31'))['value'])->toBe('kons2024')
        ->and(K3Rules::rule('k3_version_by_fiscal_year', $start, CarbonImmutable::parse('2026-11-30'), firstYear: true)['value'])->toBe('kons2024')
        ->and(K3Rules::entry('k2_1_1c.employees_gt', $start, CarbonImmutable::parse('2026-11-30'), firstYear: true))->toBeNull();
});

it('S1-R9 refuses a rule that does not exist', function () {
    K3Rules::rule('no_such_rule', CarbonImmutable::parse('2026-01-01'));
})->throws(LogicException::class);

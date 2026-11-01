<?php

declare(strict_types=1);

use App\Domain\Overview\LiquidityForecast;
use App\Domain\Overview\TodoItem;
use Carbon\CarbonImmutable;

it('adds invoices on their due month and the average result per month', function () {
    $points = LiquidityForecast::project(
        balance: 10_000_00,
        today: CarbonImmutable::parse('2026-11-01'),
        receivables: [
            ['due' => '2026-10-20', 'amount' => 1_000_00], // overdue: counts this month
            ['due' => '2026-11-30', 'amount' => 2_000_00],
            ['due' => '2026-12-15', 'amount' => 3_000_00],
            ['due' => '2027-02-01', 'amount' => 9_000_00], // after the forecast
        ],
        payables: [
            ['due' => '2026-11-12', 'amount' => 500_00],
            ['due' => '2027-01-31', 'amount' => 4_000_00],
        ],
        averageResult: 3_000_00,
    );

    expect($points)->toBe([
        // November: all 30 days are left, so the whole average result.
        ['month' => '2026-11', 'inflow' => 3_000_00, 'outflow' => 500_00, 'trend' => 3_000_00, 'balance' => 15_500_00],
        ['month' => '2026-12', 'inflow' => 3_000_00, 'outflow' => 0, 'trend' => 3_000_00, 'balance' => 21_500_00],
        ['month' => '2027-01', 'inflow' => 0, 'outflow' => 4_000_00, 'trend' => 3_000_00, 'balance' => 20_500_00],
    ]);
});

it('counts only the days left of this month, rounded to whole öre', function () {
    $points = LiquidityForecast::project(0, CarbonImmutable::parse('2026-11-21'), [], [], 1_000, 1);

    // 10 of 30 days left: 1 000 öre × 10 / 30 = 333,33 → 333 öre.
    expect($points[0]['trend'])->toBe(333)
        ->and($points[0]['balance'])->toBe(333);
});

it('handles a negative trend and an empty month', function () {
    $points = LiquidityForecast::project(5_000, CarbonImmutable::parse('2026-02-01'), [], [], -1_000, 2);

    expect(array_column($points, 'balance'))->toBe([4_000, 3_000])
        ->and(array_column($points, 'month'))->toBe(['2026-02', '2026-03']);
});

it('averages the last whole months, rounding half up', function () {
    expect(LiquidityForecast::averageResult([100, 200, 201]))->toBe(167)
        ->and(LiquidityForecast::averageResult([1, 2]))->toBe(2)
        ->and(LiquidityForecast::averageResult([-1, -2]))->toBe(-2)
        ->and(LiquidityForecast::averageResult([]))->toBe(0);
});

it('sorts todos with deadlines first, then by kind', function () {
    $item = fn (string $key, int $priority, ?string $due) => (new TodoItem($key, 'task', $key, null, '/', $priority, $due === null ? null : CarbonImmutable::parse($due)))->toArray();

    $sorted = TodoItem::sort([
        $item('closing', TodoItem::PRIORITY_CLOSING, null),
        $item('vat', TodoItem::PRIORITY_VAT, '2026-11-12'),
        $item('approve', TodoItem::PRIORITY_APPROVE, null),
        $item('invoice', TodoItem::PRIORITY_OVERDUE_INVOICE, '2026-10-20'),
        $item('bank', TodoItem::PRIORITY_BANK, null),
    ]);

    expect(array_column($sorted, 'key'))->toBe(['invoice', 'vat', 'approve', 'bank', 'closing']);
});

it('marks a todo urgent within a week and overdue after its day', function () {
    $today = CarbonImmutable::parse('2026-11-01');
    $todo = fn (string $due) => new TodoItem('x', 'vat', 'x', null, '/', TodoItem::PRIORITY_VAT, CarbonImmutable::parse($due));

    expect($todo('2026-11-08')->isUrgent($today))->toBeTrue()
        ->and($todo('2026-11-09')->isUrgent($today))->toBeFalse()
        ->and($todo('2026-10-31')->isOverdue($today))->toBeTrue()
        ->and($todo('2026-11-01')->isOverdue($today))->toBeFalse();
});

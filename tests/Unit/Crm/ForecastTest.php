<?php

declare(strict_types=1);

use App\Modules\Crm\Domain\Forecast;
use Carbon\CarbonImmutable;

/*
 * The forecast's arithmetic (ADR-043 stage 2): value × probability per
 * month by close date, rounded half up once per bucket; overdue, undated
 * and later deals apart; won value per month next to it.
 */

function forecastAt(string $today, array $open, array $won = [], int $ahead = 3, int $back = 1): array
{
    return Forecast::build($open, $won, CarbonImmutable::parse($today, 'Europe/Stockholm'), $ahead, $back);
}

it('weighs each month\'s deals by their stage\'s probability', function () {
    $result = forecastAt('2026-06-15', [
        ['date' => '2026-06-30', 'probability' => 50, 'value' => 1_000_000, 'count' => 1],
        ['date' => '2026-06-20', 'probability' => 25, 'value' => 400_000, 'count' => 2],
        ['date' => '2026-07-01', 'probability' => 100, 'value' => 300_000, 'count' => 1],
        ['date' => '2026-08-31', 'probability' => 0, 'value' => 999_900, 'count' => 1],
    ]);

    expect(array_column($result['months'], 'month'))->toBe(['2026-05', '2026-06', '2026-07', '2026-08'])
        ->and($result['months'][1])->toMatchArray(['value' => 1_400_000, 'weighted' => 600_000, 'count' => 3])
        ->and($result['months'][2])->toMatchArray(['value' => 300_000, 'weighted' => 300_000])
        ->and($result['months'][3])->toMatchArray(['value' => 999_900, 'weighted' => 0, 'count' => 1])
        ->and($result['months'][0])->toMatchArray(['value' => 0, 'weighted' => 0]);
});

it('rounds the weighted sum half up to whole öre once per bucket, never per deal', function () {
    // 3 × (1 öre × 50 %) is 1.5 öre: rounded per deal it would be 3, per bucket it is 2.
    $result = forecastAt('2026-06-15', array_fill(0, 3, ['date' => '2026-06-30', 'probability' => 50, 'value' => 1, 'count' => 1]));

    expect($result['months'][1]['weighted'])->toBe(2)
        ->and(Forecast::percentOf(149))->toBe(1)
        ->and(Forecast::percentOf(150))->toBe(2)
        ->and(Forecast::percentOf(0))->toBe(0);
});

it('puts a passed close date, a missing one and one beyond the last month in buckets of their own', function () {
    $result = forecastAt('2026-06-15', [
        ['date' => '2026-06-14', 'probability' => 50, 'value' => 100_000, 'count' => 1],
        ['date' => '2026-01-31', 'probability' => 10, 'value' => 100_000, 'count' => 1],
        ['date' => '2026-06-15', 'probability' => 10, 'value' => 100_000, 'count' => 1],
        ['date' => null, 'probability' => 75, 'value' => 200_000, 'count' => 2],
        ['date' => '2026-09-01', 'probability' => 20, 'value' => 500_000, 'count' => 1],
    ]);

    expect($result['overdue'])->toBe(['value' => 200_000, 'weighted' => 60_000, 'count' => 2])
        ->and($result['undated'])->toBe(['value' => 200_000, 'weighted' => 150_000, 'count' => 2])
        ->and($result['later'])->toBe(['value' => 500_000, 'weighted' => 100_000, 'count' => 1])
        // Today itself is not overdue.
        ->and($result['months'][1])->toMatchArray(['value' => 100_000, 'weighted' => 10_000, 'count' => 1]);
});

it('shows the won value per month, also for the months back, and ignores months outside the table', function () {
    $result = forecastAt('2026-06-15', [], [
        ['month' => '2026-05', 'value' => 250_000, 'count' => 2],
        ['month' => '2026-06', 'value' => 100_000, 'count' => 1],
        ['month' => '2025-12', 'value' => 999_999, 'count' => 9],
    ]);

    expect($result['months'][0])->toMatchArray(['won' => 250_000, 'won_count' => 2])
        ->and($result['months'][1])->toMatchArray(['won' => 100_000, 'won_count' => 1])
        ->and(array_sum(array_column($result['months'], 'won')))->toBe(350_000);
});

it('crosses a year end and keeps at least one month ahead', function () {
    $result = forecastAt('2026-12-31', [['date' => '2027-01-15', 'probability' => 40, 'value' => 100, 'count' => 1]], [], 2, 0);

    expect(array_column($result['months'], 'month'))->toBe(['2026-12', '2027-01'])
        ->and($result['months'][1]['weighted'])->toBe(40)
        ->and(array_column(forecastAt('2026-06-15', [], [], 0, 0)['months'], 'month'))->toBe(['2026-06']);
});

it('reads today in Swedish time, so a close date of today stays in the month late in the evening UTC', function () {
    $result = Forecast::build([['date' => '2026-07-01', 'probability' => 100, 'value' => 100, 'count' => 1]], [], CarbonImmutable::parse('2026-06-30 22:30', 'UTC'), 2, 0);

    expect($result['overdue']['count'])->toBe(0)
        ->and($result['months'][0]['month'])->toBe('2026-07');
});

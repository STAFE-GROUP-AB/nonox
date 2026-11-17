<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Demand;

/**
 * How a day's sales spread over its hours: weights per local hour, from
 * the till's receipts on the forecast's basis days, or from the company's
 * own hour profile ("Egen uppskattning"). Only the shape; the day's total
 * comes from the forecast.
 */
final readonly class HourShape
{
    public const string TILL = 'TILL';

    public const string PROFILE = 'PROFILE';

    /** @param  array<int, int>  $weights  local hour => weight (öre or basis points) */
    public function __construct(public string $source, public array $weights) {}

    /**
     * The till's net sales per hour summed over the basis days; null when
     * the till has nothing positive on them.
     *
     * @param  array<string, array<int, int>>  $tillByDay  Y-m-d => local hour => öre
     * @param  list<string>  $basis
     */
    public static function fromTill(array $tillByDay, array $basis): ?self
    {
        $weights = [];

        foreach ($basis as $date) {
            foreach ($tillByDay[$date] ?? [] as $hour => $ore) {
                $weights[$hour] = ($weights[$hour] ?? 0) + $ore;
            }
        }

        $weights = array_map(fn (int $ore): int => max(0, $ore), $weights);

        return array_sum($weights) > 0 ? new self(self::TILL, $weights) : null;
    }

    /** @param  list<int>|null  $shares  24 basis points */
    public static function fromProfile(?array $shares): ?self
    {
        if ($shares === null || array_sum($shares) <= 0) {
            return null;
        }

        return new self(self::PROFILE, array_map('intval', $shares));
    }

    /**
     * Splits an amount over the hours that exist that day, in proportion to
     * the weights, by largest remainder so the hours add up to the amount.
     *
     * @param  list<int>  $hours  the local hours of the day
     * @return array<int, int> local hour => öre
     */
    public function split(int $amount, array $hours): array
    {
        $weights = [];

        foreach ($hours as $hour) {
            $weights[$hour] = max(0, $this->weights[$hour] ?? 0);
        }

        $total = array_sum($weights);
        $result = array_fill_keys($hours, 0);

        if ($total <= 0 || $amount <= 0) {
            return $result;
        }

        $remainders = [];

        foreach ($weights as $hour => $weight) {
            $result[$hour] = intdiv($amount * $weight, $total);
            $remainders[$hour] = ($amount * $weight) % $total;
        }

        $left = $amount - array_sum($result);
        arsort($remainders);

        foreach (array_keys($remainders) as $hour) {
            if ($left <= 0) {
                break;
            }

            $result[$hour]++;
            $left--;
        }

        return $result;
    }
}

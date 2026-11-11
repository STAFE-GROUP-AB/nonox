<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/**
 * A resource's free time over the horizon (ADR-071 §13.3): sorted,
 * non-overlapping intervals [start, end) in epoch minutes. An operation
 * fills it from its earliest start, across breaks, days and the gaps left
 * by earlier operations; booking takes those stretches out.
 */
final class FreeTime
{
    /** @var list<array{0: int, 1: int}> */
    private array $free;

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    public function __construct(array $intervals)
    {
        $this->free = self::normalise($intervals);
    }

    /** @return list<array{0: int, 1: int}> */
    public function intervals(): array
    {
        return $this->free;
    }

    public function isFreeAt(int $minute): bool
    {
        $index = $this->firstEndingAfter($minute);

        return $index < count($this->free) && $this->free[$index][0] <= $minute;
    }

    /**
     * The first minutes free from $from, together with $with when given (a
     * person at a workstation): null when they do not fit before the
     * horizon.
     */
    public function fill(int $from, int $minutes, ?self $with = null): ?Fill
    {
        if ($minutes <= 0) {
            return new Fill([], $from, $from, 0);
        }

        $segments = [];
        $left = $minutes;
        $i = $this->firstEndingAfter($from);
        $count = count($this->free);

        if ($with === null) {
            for (; $i < $count && $left > 0; $i++) {
                $start = max($this->free[$i][0], $from);
                $take = min($left, $this->free[$i][1] - $start);
                $segments[] = [$start, $start + $take];
                $left -= $take;
            }
        } else {
            $j = $with->firstEndingAfter($from);
            $other = $with->free;
            $otherCount = count($other);

            while ($i < $count && $j < $otherCount && $left > 0) {
                $start = max($this->free[$i][0], $other[$j][0], $from);
                $end = min($this->free[$i][1], $other[$j][1]);

                if ($end > $start) {
                    $take = min($left, $end - $start);
                    $segments[] = [$start, $start + $take];
                    $left -= $take;
                }

                if ($this->free[$i][1] <= $other[$j][1]) {
                    $i++;
                } else {
                    $j++;
                }
            }
        }

        if ($left > 0) {
            return null;
        }

        return new Fill(self::merge($segments), $segments[0][0], $segments[count($segments) - 1][1], $minutes);
    }

    /**
     * Takes booked stretches out of the free time.
     *
     * @param  list<array{0: int, 1: int}>  $segments
     */
    public function book(array $segments): void
    {
        foreach ($segments as [$start, $end]) {
            $index = $this->firstEndingAfter($start);
            $replacement = [];
            $removeCount = 0;

            for ($k = $index; $k < count($this->free) && $this->free[$k][0] < $end; $k++) {
                [$freeStart, $freeEnd] = $this->free[$k];
                $removeCount++;

                if ($freeStart < $start) {
                    $replacement[] = [$freeStart, $start];
                }

                if ($freeEnd > $end) {
                    $replacement[] = [$end, $freeEnd];
                }
            }

            array_splice($this->free, $index, $removeCount, $replacement);
        }
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    public function add(array $intervals): void
    {
        $this->free = self::normalise([...$this->free, ...$intervals]);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    public function remove(array $intervals): void
    {
        $this->book(self::normalise($intervals));
    }

    /** Free minutes between two moments. */
    public function minutesBetween(int $from, int $to): int
    {
        $total = 0;

        for ($i = $this->firstEndingAfter($from); $i < count($this->free) && $this->free[$i][0] < $to; $i++) {
            $total += min($to, $this->free[$i][1]) - max($from, $this->free[$i][0]);
        }

        return $total;
    }

    /** Binary search: the first interval that ends after the minute. */
    private function firstEndingAfter(int $minute): int
    {
        $low = 0;
        $high = count($this->free);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->free[$middle][1] <= $minute) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    private static function normalise(array $intervals): array
    {
        $intervals = array_values(array_filter($intervals, fn (array $interval): bool => $interval[1] > $interval[0]));
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return self::merge($intervals);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals  sorted by start
     * @return list<array{0: int, 1: int}>
     */
    private static function merge(array $intervals): array
    {
        $merged = [];

        foreach ($intervals as $interval) {
            $last = count($merged) - 1;

            if ($last >= 0 && $interval[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $interval[1]);

                continue;
            }

            $merged[] = [$interval[0], $interval[1]];
        }

        return $merged;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use Carbon\CarbonImmutable;

/**
 * The share register at a date, as the entries up to that date add up to
 * (RegisterFold): every share range in number order, the registered share
 * capital and the capital of new issues not yet registered, and each
 * holder's details as they stood then.
 */
final class RegisterState
{
    /**
     * @param  list<Holding>  $holdings  sorted by share number, never overlapping
     * @param  array<int, int>  $pendingCapital  capital of unregistered new issues, by the issue's event id
     * @param  array<int, array<string, mixed>>  $snapshots  holder details by holder id
     * @param  list<int>  $voided  ids of entries voided by a correction
     */
    public function __construct(
        public readonly ?CarbonImmutable $date,
        public readonly array $holdings,
        public readonly int $capitalOre,
        public readonly array $pendingCapital,
        public readonly array $snapshots,
        public readonly array $voided,
        public readonly bool $started,
    ) {}

    public function totalShares(): int
    {
        return array_sum(array_map(fn (Holding $holding): int => $holding->count(), $this->holdings));
    }

    /** Shares whose issue is registered: the ones the share capital is made of. */
    public function registeredShares(): int
    {
        return array_sum(array_map(fn (Holding $holding): int => $holding->pendingIssueId === null ? $holding->count() : 0, $this->holdings));
    }

    public function pendingCapitalOre(): int
    {
        return array_sum($this->pendingCapital);
    }

    /**
     * The quota value in whole öre, rounded half up; null without shares.
     * It is a quotient, so it may not be a whole number of öre.
     */
    public function quotaValueOre(): ?int
    {
        $shares = $this->registeredShares();

        if ($shares === 0) {
            return null;
        }

        return intdiv(2 * $this->capitalOre + $shares, 2 * $shares);
    }

    public function quotaIsExact(): bool
    {
        $shares = $this->registeredShares();

        return $shares > 0 && $this->capitalOre % $shares === 0;
    }

    /**
     * Shares per holder, largest first.
     *
     * @return array<int, int>
     */
    public function sharesByHolder(): array
    {
        $totals = [];

        foreach ($this->holdings as $holding) {
            $totals[$holding->holderId] = ($totals[$holding->holderId] ?? 0) + $holding->count();
        }

        arsort($totals);

        return $totals;
    }

    /**
     * The holdings with neighbouring ranges that say the same joined, for
     * the printed register.
     *
     * @return list<Holding>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->holdings as $holding) {
            $last = $rows === [] ? null : $rows[array_key_last($rows)];

            if ($last !== null && $holding->continues($last)) {
                $rows[array_key_last($rows)] = $last->with(['to' => $holding->to]);

                continue;
            }

            $rows[] = $holding;
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function snapshot(int $holderId): array
    {
        return $this->snapshots[$holderId] ?? [];
    }

    public function isVoided(int $eventId): bool
    {
        return in_array($eventId, $this->voided, true);
    }

    /**
     * Votes per holder, in tenths, from the votes of each class.
     *
     * @param  array<int, int>  $votesTenthsByClass
     * @return array<int, int>
     */
    public function votesByHolder(array $votesTenthsByClass): array
    {
        $votes = [];

        foreach ($this->holdings as $holding) {
            $perShare = $holding->classId === null ? 10 : ($votesTenthsByClass[$holding->classId] ?? 10);
            $votes[$holding->holderId] = ($votes[$holding->holderId] ?? 0) + $holding->count() * $perShare;
        }

        return $votes;
    }
}

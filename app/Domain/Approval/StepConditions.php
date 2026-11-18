<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use InvalidArgumentException;

/**
 * When a step applies (ADR-081 gap 8): an amount range and lists of cost
 * centres, projects, suppliers and an account range. Every condition that is
 * set must hold; one that is not set always holds. A list holds when any of
 * the subject's values is in it, the account range when any row's account is
 * inside it. The amount is the subject's total in kronor, without sign, so a
 * credit note is tested on its size. Bounds are inclusive.
 *
 * The stored form is validated: unknown keys or wrong types are refused, so a
 * policy can never carry a condition the engine does not understand.
 */
final readonly class StepConditions
{
    public const array KEYS = ['min_ore', 'max_ore', 'cost_center_ids', 'project_ids', 'supplier_ids', 'account_from', 'account_to'];

    /**
     * @param  list<int>  $costCenterIds
     * @param  list<int>  $projectIds
     * @param  list<int>  $supplierIds
     */
    public function __construct(
        public ?int $minOre = null,
        public ?int $maxOre = null,
        public array $costCenterIds = [],
        public array $projectIds = [],
        public array $supplierIds = [],
        public ?int $accountFrom = null,
        public ?int $accountTo = null,
    ) {
        if ($minOre !== null && $minOre < 0) {
            throw new InvalidArgumentException('min_ore must not be negative.');
        }

        if ($minOre !== null && $maxOre !== null && $minOre > $maxOre) {
            throw new InvalidArgumentException('min_ore must not exceed max_ore.');
        }

        foreach ([$accountFrom, $accountTo] as $account) {
            if ($account !== null && ($account < 1000 || $account > 9999)) {
                throw new InvalidArgumentException('Accounts are numbered 1000-9999.');
            }
        }

        if ($accountFrom !== null && $accountTo !== null && $accountFrom > $accountTo) {
            throw new InvalidArgumentException('account_from must not exceed account_to.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $unknown = array_diff(array_keys($data), self::KEYS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown step condition: '.implode(', ', $unknown));
        }

        return new self(
            minOre: self::int($data, 'min_ore'),
            maxOre: self::int($data, 'max_ore'),
            costCenterIds: self::ids($data, 'cost_center_ids'),
            projectIds: self::ids($data, 'project_ids'),
            supplierIds: self::ids($data, 'supplier_ids'),
            accountFrom: self::int($data, 'account_from'),
            accountTo: self::int($data, 'account_to'),
        );
    }

    /** @return array<string, int|list<int>> */
    public function toArray(): array
    {
        return array_filter([
            'min_ore' => $this->minOre,
            'max_ore' => $this->maxOre,
            'cost_center_ids' => $this->costCenterIds,
            'project_ids' => $this->projectIds,
            'supplier_ids' => $this->supplierIds,
            'account_from' => $this->accountFrom,
            'account_to' => $this->accountTo,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * The keys that are set, for checking them against what a subject type supports.
     *
     * @return list<string>
     */
    public function usedKeys(): array
    {
        return array_keys($this->toArray());
    }

    public function matches(SubjectFacts $facts): bool
    {
        $amount = abs($facts->amountOre);

        if ($this->minOre !== null && $amount < $this->minOre) {
            return false;
        }

        if ($this->maxOre !== null && $amount > $this->maxOre) {
            return false;
        }

        if ($this->costCenterIds !== [] && array_intersect($this->costCenterIds, $facts->costCenterIds) === []) {
            return false;
        }

        if ($this->projectIds !== [] && array_intersect($this->projectIds, $facts->projectIds) === []) {
            return false;
        }

        if ($this->supplierIds !== [] && ! in_array($facts->supplierId, $this->supplierIds, true)) {
            return false;
        }

        if ($this->accountFrom === null && $this->accountTo === null) {
            return true;
        }

        $from = $this->accountFrom ?? 1000;
        $to = $this->accountTo ?? 9999;

        foreach ($facts->accountNumbers as $number) {
            if ($number >= $from && $number <= $to) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $data */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new InvalidArgumentException("{$key} must be an integer.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function ids(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$key} must be a list of ids.");
        }

        foreach ($value as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException("{$key} must be a list of ids.");
            }
        }

        return array_values(array_unique($value));
    }
}

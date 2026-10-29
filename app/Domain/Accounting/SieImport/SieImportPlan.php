<?php

declare(strict_types=1);

namespace App\Domain\Accounting\SieImport;

use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\Dimension;
use Carbon\CarbonImmutable;

/**
 * What reading a SIE file in would do, and what stops it: the validation
 * report of the two-step import (AGENTS.md §9.2). Built by SieImportAnalyser,
 * shown to the user and built again inside the import itself.
 */
final class SieImportPlan
{
    /** @var list<string> */
    public array $blockers = [];

    /** @var list<string> */
    public array $warnings = [];

    public string $checksum = 'absent';

    public ?string $program = null;

    public ?string $organisationNumber = null;

    public ?string $companyName = null;

    public ?CarbonImmutable $start = null;

    public ?CarbonImmutable $end = null;

    public ?int $fiscalYearId = null;

    public bool $createFiscalYear = false;

    /** @var array<int, array{name: string, type: AccountType, sru: ?string}> */
    public array $accountsToCreate = [];

    public int $unusedAccounts = 0;

    /** @var array<string, array<string, string>> dimension => code => name */
    public array $objectsToCreate = [];

    /**
     * Per series code in the file: how many vouchers, whether the series has
     * to be created, and whether the file's numbers are kept or the vouchers
     * get new numbers from $first on.
     *
     * @var array<string, array{vouchers: int, create: bool, keep: bool, first: int}>
     */
    public array $series = [];

    /** @var array<int, int> account number => öre */
    public array $openingBalances = [];

    public bool $writeOpeningBalances = false;

    /** @var array<int, int> account number => öre, the file's #UB 0 */
    public array $closingBalances = [];

    /** @var array<int, int> account number => öre, the file's #RES 0 */
    public array $results = [];

    public int $vouchers = 0;

    public int $rows = 0;

    public int $addedRows = 0;

    public int $removedRows = 0;

    public int $existingVouchers = 0;

    public function block(string $message): void
    {
        $this->blockers[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function fiscalYearLabel(): ?string
    {
        if ($this->start === null || $this->end === null) {
            return null;
        }

        return $this->start->year === $this->end->year
            ? (string) $this->start->year
            : "{$this->start->year}/{$this->end->year}";
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function objectList(Dimension $dimension): array
    {
        $objects = $this->objectsToCreate[$dimension->value] ?? [];

        return array_map(
            fn (int|string $code, string $name): array => ['code' => (string) $code, 'name' => $name],
            array_keys($objects),
            $objects,
        );
    }

    /**
     * The report as stored with the import and shown on its page.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'checksum' => $this->checksum,
            'program' => $this->program,
            'organisation_number' => $this->organisationNumber,
            'company_name' => $this->companyName,
            'fiscal_year' => [
                'label' => $this->fiscalYearLabel(),
                'start' => $this->start?->toDateString(),
                'end' => $this->end?->toDateString(),
                'create' => $this->createFiscalYear,
            ],
            'accounts_to_create' => array_map(
                fn (int $number, array $account): array => ['number' => $number, 'name' => $account['name']],
                array_keys($this->accountsToCreate),
                $this->accountsToCreate,
            ),
            'unused_accounts' => $this->unusedAccounts,
            'cost_centers_to_create' => $this->objectList(Dimension::CostCenter),
            'projects_to_create' => $this->objectList(Dimension::Project),
            'series' => array_map(
                fn (int|string $code, array $series): array => ['code' => (string) $code, ...$series],
                array_keys($this->series),
                $this->series,
            ),
            'opening_balances' => count($this->openingBalances),
            'write_opening_balances' => $this->writeOpeningBalances,
            'vouchers' => $this->vouchers,
            'rows' => $this->rows,
            'added_rows' => $this->addedRows,
            'removed_rows' => $this->removedRows,
            'existing_vouchers' => $this->existingVouchers,
        ];
    }
}

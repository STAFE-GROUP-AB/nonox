<?php

declare(strict_types=1);

namespace App\Domain\Budget\Sie;

/**
 * What reading the budget of a SIE file in would do, and what stops it:
 * the report of step one of the budget import (ADR-081 E4). Built by
 * SieBudgetAnalyser, shown to the user and built again by ImportSieBudget.
 */
final class SieBudgetPlan
{
    /** @var list<string> */
    public array $blockers = [];

    /** @var list<string> */
    public array $warnings = [];

    public ?string $program = null;

    public ?string $companyName = null;

    public ?string $organisationNumber = null;

    public ?int $fiscalYearId = null;

    public ?string $fiscalYearLabel = null;

    /** @var list<array{account_id: int, cost_center_id: ?int, project_id: ?int, period: string, amount_ore: int}> */
    public array $lines = [];

    public int $accounts = 0;

    public int $costCenters = 0;

    public int $projects = 0;

    /** Revenue minus costs in öre, as the budget's result (credit is negative in the lines). */
    public int $result = 0;

    public function block(string $message): void
    {
        $this->blockers[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * The report as shown on the page.
     *
     * @return array{blockers: list<string>, warnings: list<string>, program: ?string, company_name: ?string, organisation_number: ?string, fiscal_year: ?string, lines: int, accounts: int, cost_centers: int, projects: int, result_ore: int}
     */
    public function toArray(): array
    {
        return [
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'program' => $this->program,
            'company_name' => $this->companyName,
            'organisation_number' => $this->organisationNumber,
            'fiscal_year' => $this->fiscalYearLabel,
            'lines' => count($this->lines),
            'accounts' => $this->accounts,
            'cost_centers' => $this->costCenters,
            'projects' => $this->projects,
            'result_ore' => $this->result,
        ];
    }
}

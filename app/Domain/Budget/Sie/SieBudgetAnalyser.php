<?php

declare(strict_types=1);

namespace App\Domain\Budget\Sie;

use App\Domain\Company\OrganisationNumber;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Support\Sie\SieChecksum;
use App\Support\Sie\SieReader;
use App\Support\Tenancy\CurrentCompany;

/**
 * Reads only the budget of a SIE file of type 2, 3 or 4, without changing
 * anything, and works out the budget version it would become (ADR-081 E4):
 * for a company whose bookkeeping is already here, or a file from a
 * planning program. The bookkeeping in the file is not read in.
 *
 * Accounts, cost centres and projects are matched by number and code inside
 * the company (ADR-081 gap 2, "Tenancy risks"); one that is missing stops the
 * import, since a budget import never changes the chart of accounts or the
 * registers. Six queries, whatever the size of the file.
 */
final class SieBudgetAnalyser
{
    public function analyse(Company $company, string $path): SieBudgetPlan
    {
        return CurrentCompany::run($company, function () use ($company, $path): SieBudgetPlan {
            $plan = new SieBudgetPlan;
            $budget = new SieBudget;
            $reader = new SieReader($path);

            foreach ($reader->records() as $record) {
                $budget->read($record);

                match ($record->label) {
                    '#PROGRAM' => $plan->program = trim($record->field(0).' '.$record->field(1)),
                    '#ORGNR' => $plan->organisationNumber = $record->field(0),
                    '#FNAMN' => $plan->companyName = $record->field(0),
                    default => null,
                };
            }

            $this->checkFile($plan, $reader, $budget, $company);
            $fiscalYear = $this->fiscalYear($plan, $budget);

            if (! $budget->present()) {
                $plan->block($budget->otherYears() > 0
                    ? __('Filen har bara budget för andra räkenskapsår än sitt eget (#RAR 0).')
                    : __('Filen har ingen budget (#PBUDGET).'));

                return $plan;
            }

            foreach ($budget->problems() as $problem) {
                $plan->block($problem);
            }

            foreach ($budget->notes() as $note) {
                $plan->warn($note);
            }

            if ($plan->blockers === [] && $fiscalYear !== null) {
                $this->resolve($plan, $budget);
            }

            return $plan;
        });
    }

    private function checkFile(SieBudgetPlan $plan, SieReader $reader, SieBudget $budget, Company $company): void
    {
        foreach ($reader->problems() as $problem) {
            $plan->block($problem);
        }

        if ($reader->checksum() === SieChecksum::Absent) {
            $plan->warn(__('Filen saknar kontrollsumma, så det går inte att se om den har ändrats på vägen.'));
        }

        if (! in_array($budget->type(), ['2', '3', '4'], true)) {
            $plan->block($budget->type() === null
                ? __('Filen saknar uppgift om SIE-typ. Det verkar inte vara en SIE-fil.')
                : __('Filen är av SIE-typ :type, som inte har någon budget. Ta fram en fil av typ 2, 3 eller 4.', ['type' => $budget->type()]));
        }

        $theirs = OrganisationNumber::tryFrom((string) $plan->organisationNumber);
        $ours = OrganisationNumber::tryFrom((string) $company->org_number);

        if ($plan->organisationNumber === null) {
            $plan->warn(__('Filen anger inget organisationsnummer. Kontrollera att den gäller rätt företag.'));
        } elseif ($theirs !== null && $ours !== null && $theirs->digits !== $ours->digits) {
            $plan->block(__('Filen gäller organisationsnummer :theirs, men det här företaget har :ours. Läs in budgeten i rätt företag.', [
                'theirs' => $theirs->formatted(),
                'ours' => $ours->formatted(),
            ]));
        }
    }

    private function fiscalYear(SieBudgetPlan $plan, SieBudget $budget): ?FiscalYear
    {
        $start = $budget->start();
        $end = $budget->end();

        if ($start === null || $end === null) {
            $plan->block(__('Filen saknar räkenskapsår (#RAR 0).'));

            return null;
        }

        $fiscalYear = FiscalYear::query()->whereDate('start_date', $start)->whereDate('end_date', $end)->first();

        if (! $fiscalYear instanceof FiscalYear) {
            $plan->block(__('Räkenskapsåret :start – :end finns inte här. Skapa det under Räkenskapsår, eller läs in hela SIE4-filen under Läs in SIE-fil.', [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ]));

            return null;
        }

        $plan->fiscalYearId = $fiscalYear->id;
        $plan->fiscalYearLabel = $fiscalYear->label();

        return $fiscalYear;
    }

    /**
     * Matches the lines' accounts and objects with the company's own.
     */
    private function resolve(SieBudgetPlan $plan, SieBudget $budget): void
    {
        $lines = $budget->lines();
        $numbers = array_values(array_unique(array_column($lines, 'account')));
        $accounts = Account::query()->whereIn('number', $numbers)->pluck('id', 'number');
        $costCenters = CostCenter::query()->whereIn('code', $budget->costCenterCodes())->pluck('id', 'code');
        $projects = Project::query()->whereIn('code', $budget->projectCodes())->pluck('id', 'code');

        $missing = array_values(array_filter($numbers, fn (int $number): bool => ! $accounts->has($number)));
        sort($missing);

        if ($missing !== []) {
            $plan->block(__('Konto :accounts finns inte i kontoplanen här. Lägg till kontona under Kontoplan och granska filen igen.', ['accounts' => implode(', ', array_slice($missing, 0, 10))]));
        }

        $absentCostCenters = array_values(array_filter($budget->costCenterCodes(), fn (string $code): bool => ! $costCenters->has($code)));
        $absentProjects = array_values(array_filter($budget->projectCodes(), fn (string $code): bool => ! $projects->has($code)));

        if ($absentCostCenters !== []) {
            $plan->block(__('Kostnadsställe :codes finns inte här. Lägg till det under Kostnadsställen och projekt och granska filen igen.', ['codes' => implode(', ', array_slice($absentCostCenters, 0, 10))]));
        }

        if ($absentProjects !== []) {
            $plan->block(__('Projekt :codes finns inte här. Lägg till det under Kostnadsställen och projekt och granska filen igen.', ['codes' => implode(', ', array_slice($absentProjects, 0, 10))]));
        }

        if ($plan->blockers !== []) {
            return;
        }

        foreach ($lines as $line) {
            $plan->lines[] = [
                'account_id' => (int) $accounts[$line['account']],
                'cost_center_id' => $line['cost_center'] === null ? null : (int) $costCenters[$line['cost_center']],
                'project_id' => $line['project'] === null ? null : (int) $projects[$line['project']],
                'period' => "{$line['period']}-01",
                'amount_ore' => $line['amount'],
            ];
        }

        $plan->accounts = count($numbers);
        $plan->costCenters = count($budget->costCenterCodes());
        $plan->projects = count($budget->projectCodes());
        // The lines add up to each account's whole amount, so their sum is the budget's result (credit negative).
        $plan->result = -array_sum(array_column($lines, 'amount'));
    }
}

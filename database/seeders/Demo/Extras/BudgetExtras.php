<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\ManageBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Domain\Budget\BudgetActuals;
use App\Domain\Budget\BudgetMonths;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Budgets (ADR-081 E3) for the manual's budget pages, in the consultancy: a
 * first proposal made from the year's own figures, locked, and the budget the
 * reports use, a copy of it plus 3 %. Each account's budget differs a little
 * from its outcome, so Budget mot utfall shows variances both ways.
 */
final class BudgetExtras extends DemoExtra
{
    public const string PROPOSAL = 'Första förslaget';

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Budget', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $proposal = app(CreateBudgetVersion::class)($c->user, $c->company, [
                'fiscal_year_id' => $c->fiscalYear->id,
                'name' => self::PROPOSAL,
                'start' => CreateBudgetVersion::EMPTY,
            ]);

            app(SaveBudgetLines::class)($c->user, $proposal, $this->rows($c));
            app(ManageBudgetVersion::class)->lock($c->user, $proposal->refresh());

            $budget = app(CreateBudgetVersion::class)($c->user, $c->company, [
                'fiscal_year_id' => $c->fiscalYear->id,
                'name' => __('Budget :year', ['year' => $c->fiscalYear->start_date->format('Y')]),
                'start' => CreateBudgetVersion::COPY,
                'source_version_id' => $proposal->id,
                'percent' => '3',
            ]);

            app(ManageBudgetVersion::class)->activate($c->user, $budget);
        }));
    }

    /**
     * Every result account with an outcome: the months so far near their
     * outcome, the rest at the average month, in whole hundreds of kronor.
     *
     * @return list<array{account_id: int, cost_center_id: ?int, project_id: ?int, amounts: array<string, int>}>
     */
    private function rows(DemoContext $c): array
    {
        $months = BudgetMonths::of($c->fiscalYear);
        $rows = [];

        foreach (BudgetActuals::perMonth($c->fiscalYear) as $accountId => $perMonth) {
            $average = intdiv(array_sum($perMonth), max(1, count($perMonth)));

            if ($average === 0) {
                continue;
            }

            // A made-up, steady difference per account: between −6 % and +6 % of the outcome.
            $factor = 100 + ((int) $accountId % 5 - 2) * 3;
            $amounts = [];

            foreach ($months as $month) {
                $amount = intdiv(($perMonth[$month] ?? $average) * $factor, 100);
                $amounts[$month] = intdiv($amount, 10_000) * 10_000;
            }

            $rows[] = ['account_id' => (int) $accountId, 'cost_center_id' => null, 'project_id' => null, 'amounts' => array_filter($amounts)];
        }

        return $rows;
    }
}

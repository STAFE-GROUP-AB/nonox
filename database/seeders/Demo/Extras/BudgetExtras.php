<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\CreateForecast;
use App\Actions\Budget\ForecastInput;
use App\Actions\Budget\ManageBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Actions\Budget\SaveForecast;
use App\Domain\Budget\BudgetActuals;
use App\Domain\Budget\BudgetMonths;
use App\Domain\Budget\BudgetScope;
use App\Domain\Budget\ForecastEngine;
use App\Domain\Budget\ForecastMethod;
use App\Models\BudgetVersion;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Budgets (ADR-081 E3) for the manual's budget pages, in the consultancy: a
 * first proposal made from the year's own figures, locked, and the budget the
 * reports use, a copy of it plus 3 %. Each account's budget differs a little
 * from its outcome, so Budget mot utfall shows variances both ways. And a
 * rolling forecast (E5) from the last ended month with the default methods,
 * one month of it typed by hand, for the forecast pages.
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

            $this->forecast($c);
        }));
    }

    /** The default forecast (owner decision 7) and an expected extra cost typed into its last month. */
    private function forecast(DemoContext $c): void
    {
        $cutoff = ForecastInput::defaultCutoff($c->fiscalYear);

        if ($cutoff === null) {
            return;
        }

        $methods = ['revenue_method' => ForecastMethod::Budget->value, 'cost_method' => ForecastMethod::Average->value, 'average_months' => 3, 'growth' => ''];
        $forecast = app(CreateForecast::class)($c->user, $c->company, [
            'fiscal_year_id' => $c->fiscalYear->id,
            'name' => __('Prognos :month', ['month' => ForecastInput::monthName($cutoff)]),
            'cutoff' => $cutoff,
            ...$methods,
        ]);

        $months = BudgetMonths::of($c->fiscalYear);
        $last = $months[count($months) - 1];

        if ($last <= $cutoff) {
            return;
        }

        // The largest cost of the last month, plus 25 000 kr: a known one-off the averages cannot see.
        $line = BudgetScope::lines($forecast->id)
            ->join('accounts', 'accounts.id', '=', 'budget_lines.account_id')
            ->where('accounts.number', '>=', 4000)
            ->whereDate('budget_lines.period', "{$last}-01")
            ->orderByDesc('budget_lines.amount_ore')
            ->first(['budget_lines.account_id', 'budget_lines.cost_center_id', 'budget_lines.project_id', 'budget_lines.amount_ore']);

        if ($line === null) {
            return;
        }

        app(SaveForecast::class)($c->user, BudgetVersion::query()->findOrFail($forecast->id), [
            ...$methods,
            'overrides' => [ForecastEngine::key($line->account_id, $line->cost_center_id, $line->project_id) => [$last => $line->amount_ore->ore + 25_000_00]],
        ]);
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

<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Budget\SaveBudgetLines;
use App\Domain\Budget\BudgetActuals;
use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\BudgetScope;
use App\Models\BudgetVersion;
use App\Models\Project;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * The project results (ADR-081 E7) for the manual, in the consultancy: P-101
 * gets a subcontractor and travel costs over three months on top of the
 * invoice of ProjectSalesExtras, and the active budget of BudgetExtras gets
 * lines on P-101 a little above and below the outcome, so the project page
 * shows variances both ways. Runs after both (file name order).
 */
final class ProjectTrackingExtras extends DemoExtra
{
    /** The account the manual's screenshot opens the rows of. */
    public const string DRILL_ACCOUNT = '6550';

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Projektresultat', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $project = Project::query()->where('code', 'P-101')->firstOrFail();

            foreach ([3, 2, 1] as $monthsAgo) {
                $date = $c->today->subMonths($monthsAgo)->startOfMonth()->addDays(14)->max($c->fiscalYear->start_date)->toDateString();

                app(PostVoucher::class)($c->user, app(SaveDraftVoucher::class)($c->user, $c->company, [
                    'voucher_date' => $date,
                    'description' => 'Underkonsult, systembyte',
                    'series' => 'A',
                    'rows' => [
                        ['account' => 6550, 'debit_ore' => 400_000 + $monthsAgo * 50_000, 'cost_center' => 'RADG', 'project' => 'P-101', 'description' => 'Underkonsult, integrationer'],
                        ['account' => 5800, 'debit_ore' => 90_000, 'cost_center' => 'RADG', 'project' => 'P-101', 'description' => 'Resor till kund'],
                        ['account' => 1930, 'credit_ore' => 490_000 + $monthsAgo * 50_000],
                    ],
                ]));
            }

            $version = BudgetVersion::query()->where('fiscal_year_id', $c->fiscalYear->id)
                ->where('kind', BudgetKind::Budget->value)->where('is_active', true)->first();

            if (! $version instanceof BudgetVersion || $version->isLocked()) {
                return;
            }

            app(SaveBudgetLines::class)($c->user, $version, [...$this->existingRows($version), ...$this->projectRows($c, $project)]);
        }));
    }

    /**
     * The version's lines as they are, as rows for SaveBudgetLines (which
     * replaces the whole grid).
     *
     * @return list<array{account_id: int, cost_center_id: ?int, project_id: ?int, amounts: array<string, int>}>
     */
    private function existingRows(BudgetVersion $version): array
    {
        $rows = [];

        foreach (BudgetScope::lines($version->id)->toBase()->get(['account_id', 'cost_center_id', 'project_id', 'period', 'amount_ore']) as $line) {
            $key = "{$line->account_id}|{$line->cost_center_id}|{$line->project_id}";
            $rows[$key] ??= [
                'account_id' => (int) $line->account_id,
                'cost_center_id' => $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                'project_id' => $line->project_id === null ? null : (int) $line->project_id,
                'amounts' => [],
            ];
            $rows[$key]['amounts'][substr((string) $line->period, 0, 7)] = (int) $line->amount_ore;
        }

        return array_values($rows);
    }

    /**
     * Budget lines on the project: each account's outcome per month, revenue
     * planned 10 % higher and costs 5 % lower, in whole hundreds of kronor.
     *
     * @return list<array{account_id: int, cost_center_id: ?int, project_id: ?int, amounts: array<string, int>}>
     */
    private function projectRows(DemoContext $c, Project $project): array
    {
        $rows = [];

        foreach (BudgetActuals::perMonth($c->fiscalYear, projectId: $project->id) as $accountId => $perMonth) {
            $amounts = [];

            foreach ($perMonth as $month => $amount) {
                $planned = intdiv($amount * ($amount < 0 ? 110 : 95), 100);
                $planned = intdiv($planned, 10_000) * 10_000;

                if ($planned !== 0) {
                    $amounts[$month] = $planned;
                }
            }

            if ($amounts !== []) {
                $rows[] = ['account_id' => (int) $accountId, 'cost_center_id' => null, 'project_id' => $project->id, 'amounts' => $amounts];
            }
        }

        return $rows;
    }
}

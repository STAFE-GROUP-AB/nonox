<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Actions\Invoicing\SaveArticle;
use App\Domain\Company\ReportingFramework;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\InvoiceRow;
use App\Models\Project;
use App\Modules\WorkOrders\Domain\FixedPriceMethod;
use App\Modules\WorkOrders\Domain\OngoingWork;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Work in progress (upparbetat) on projects without a work order at a
 * balance sheet date (ADR-081 gap 6, E8): the rules of OngoingWork (ADR-074
 * §7), generalised to the project register.
 *
 * - Fast pris: the contract sum and the estimated total cost from the
 *   register, the costs booked on the project (posted rows on 4000–7999 up
 *   to the date) and what is invoiced on it (the net of issued invoice rows
 *   with the project, credit invoices netted). The proposal is
 *   OngoingWork::fixedPrice: degree of completion, revenue to date and the
 *   difference against the invoiced on 1620 or 2450 (main rule), or the
 *   invoiced to 2450 (alternative rule, K3 färdigställandemetoden).
 * - Löpande räkning: NONOX holds no billable value for a project without a
 *   work order (owner decision 16), so the figures are shown with "kräver
 *   arbetsorder för upparbetat värde" and nothing is proposed.
 * - Internal projects and projects without a price form have no customer
 *   price and are left out. Projects with a work order that the work order's
 *   own step (Arbetsorder → Pågående arbeten) counts on the date are left out
 *   too (OngoingWork::countedOn), so nothing is counted twice or missed.
 * - K1 gets figures only.
 *
 * The revenue account of the proposal is the one most invoiced on the
 * project, otherwise 3001. Nothing is booked here; the proposal becomes
 * draft vouchers through ProposeProjectWipVouchers, reversed the next day.
 *
 * Five queries, whatever the number of projects.
 *
 * TODO(legal-verify): K2 (BFNAR 2016:10) 6.13–6.24 and K3 chapter 23 for
 * WIP on projects without work orders (as ADR-074 §7 for work orders), and
 * L12/L13 (the accounts 1470, 1620, 2450 and which proposals per framework).
 * TODO(legal-verify): whether the costs booked on the project (4000–7999,
 * all of them) are the right base for the degree of completion (K2 6.17,
 * K3 23.22 "nedlagda utgifter"), and how WIP applies to a company using
 * kontantmetoden.
 */
final class ProjectWip
{
    public const string FIXED = 'FIXED';

    public const string NEEDS_WORK_ORDER = 'NEEDS_WORK_ORDER';

    /**
     * @return array{
     *     lines: list<array{project: Project, kind: string, figures: array{contract: ?int, costs: int, estimated: ?int, degree_bp: ?int, earned: ?int, invoiced: int}, adjustment: int, revenue_account: int, rows: list<array{account: int, debit_ore: int, credit_ore: int}>, note: ?string}>,
     *     method: FixedPriceMethod,
     *     with_work_orders: int,
     *     fingerprint: string
     * }
     */
    public static function at(Company $company, CarbonImmutable $date, ?int $projectId = null): array
    {
        return CurrentCompany::run($company, function () use ($company, $date, $projectId): array {
            $method = FixedPriceMethods::for($company);
            $k1 = $company->reporting_framework === ReportingFramework::K1;
            $projects = Project::query()
                ->whereIn('price_form', [ProjectPriceForm::Fixed->value, ProjectPriceForm::TimeAndMaterial->value])
                ->when($projectId !== null, fn ($query) => $query->whereKey($projectId))
                ->orderBy('code')
                ->get();

            if ($projects->isEmpty()) {
                return ['lines' => [], 'method' => $method, 'with_work_orders' => 0, 'fingerprint' => self::fingerprint($date, $method, [])];
            }

            $ids = $projects->pluck('id')->all();
            // Only the work orders the work order step counts on this date take a project over (no double counting, no gap).
            $withWorkOrders = OngoingWork::countedOn(WorkOrder::query(), $date)->whereIn('project_id', $ids)
                ->distinct()->pluck('project_id')->map(fn (mixed $id): int => (int) $id)->all();
            $projects = $projects->reject(fn (Project $project): bool => in_array($project->id, $withWorkOrders, true))->values();
            $ids = $projects->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
            $costs = OngoingWork::projectCosts($ids, $date);
            [$invoiced, $accounts] = self::invoiced($ids, $date);
            $lines = [];

            foreach ($projects as $project) {
                $line = self::line($project, (int) ($costs[$project->id] ?? 0), (int) ($invoiced[$project->id] ?? 0), $accounts[$project->id] ?? self::defaultRevenueAccount(), $method, $k1);

                if ($line !== null) {
                    $lines[] = $line;
                }
            }

            return ['lines' => $lines, 'method' => $method, 'with_work_orders' => count($withWorkOrders), 'fingerprint' => self::fingerprint($date, $method, $lines)];
        });
    }

    /**
     * Whether the company has a fixed-price project that the work order step
     * does not cover on the date, for the closing checklist's reminder. One
     * query.
     */
    public static function applies(CarbonImmutable $date): bool
    {
        return Project::query()
            ->where('price_form', ProjectPriceForm::Fixed->value)
            ->whereNotIn('id', OngoingWork::countedOn(WorkOrder::query(), $date)->whereNotNull('project_id')->select('project_id'))
            ->exists();
    }

    /**
     * @return ?array{project: Project, kind: string, figures: array{contract: ?int, costs: int, estimated: ?int, degree_bp: ?int, earned: ?int, invoiced: int}, adjustment: int, revenue_account: int, rows: list<array{account: int, debit_ore: int, credit_ore: int}>, note: ?string}
     */
    private static function line(Project $project, int $costs, int $invoiced, int $revenueAccount, FixedPriceMethod $method, bool $k1): ?array
    {
        if ($costs === 0 && $invoiced === 0) {
            return null;
        }

        $contract = $project->contract_sum_ore?->ore;
        $estimated = $project->estimated_cost_ore?->ore;

        if ($project->price_form !== ProjectPriceForm::Fixed) {
            return [
                'project' => $project,
                'kind' => self::NEEDS_WORK_ORDER,
                'figures' => ['contract' => null, 'costs' => $costs, 'estimated' => null, 'degree_bp' => null, 'earned' => null, 'invoiced' => $invoiced],
                'adjustment' => 0,
                'revenue_account' => $revenueAccount,
                'rows' => [],
                'note' => (string) __('Kräver arbetsorder för upparbetat värde. Ett projekt på löpande räkning utan arbetsorder har inget registrerat arbete att räkna fram värdet ur.'),
            ];
        }

        if ($contract === null) {
            return [
                'project' => $project,
                'kind' => self::FIXED,
                'figures' => ['contract' => null, 'costs' => $costs, 'estimated' => $estimated, 'degree_bp' => null, 'earned' => null, 'invoiced' => $invoiced],
                'adjustment' => 0,
                'revenue_account' => $revenueAccount,
                'rows' => [],
                'note' => (string) __('Skriv kontraktssumman i projektregistret för att få ett förslag.'),
            ];
        }

        $proposal = OngoingWork::fixedPrice($contract, $costs, $estimated, $invoiced, $method, $k1, $revenueAccount, (string) __('Välj metod för fastprisuppdrag på den här sidan för att få ett förslag.'), withChangeOrders: false);
        $adjustment = 0;

        foreach ($proposal['rows'] as $row) {
            $adjustment += match ($row['account']) {
                OngoingWork::UNBILLED => $row['debit_ore'] - $row['credit_ore'],
                OngoingWork::BILLED_AHEAD => $row['debit_ore'] - $row['credit_ore'],
                default => 0,
            };
        }

        return [
            'project' => $project,
            'kind' => self::FIXED,
            'figures' => ['contract' => $contract, 'costs' => $costs, 'estimated' => $estimated, 'degree_bp' => $proposal['degree_bp'], 'earned' => $proposal['earned'], 'invoiced' => $invoiced],
            'adjustment' => $adjustment,
            'revenue_account' => $revenueAccount,
            'rows' => $proposal['rows'],
            'note' => $proposal['note'],
        ];
    }

    /**
     * What is invoiced on each project up to the date (issued invoices, credit
     * invoices netted, in kronor), and the revenue account most invoiced on
     * it. One query.
     *
     * @param  array<int, int>  $ids
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private static function invoiced(array $ids, CarbonImmutable $date): array
    {
        if ($ids === []) {
            return [[], []];
        }

        $sums = InvoiceRow::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_rows.invoice_id')
            ->join('accounts', 'accounts.id', '=', 'invoice_rows.account_id')
            ->whereIn('invoice_rows.project_id', $ids)
            ->where('invoices.status', '<>', InvoiceStatus::Draft->value)
            ->whereDate('invoices.invoice_date', '<=', $date->toDateString())
            ->groupBy('invoice_rows.project_id', 'accounts.number')
            ->selectRaw('invoice_rows.project_id, accounts.number, SUM(invoice_rows.net_ore) AS net')
            ->toBase()
            ->get();
        $invoiced = [];
        $best = [];

        foreach ($sums as $sum) {
            $project = (int) $sum->project_id;
            $net = (int) $sum->net;
            $number = (int) $sum->number;
            $invoiced[$project] = ($invoiced[$project] ?? 0) + $net;

            if (! isset($best[$project]) || $net > $best[$project][1] || ($net === $best[$project][1] && $number < $best[$project][0])) {
                $best[$project] = [$number, $net];
            }
        }

        return [$invoiced, array_map(fn (array $pair): int => $pair[0], $best)];
    }

    private static function defaultRevenueAccount(): int
    {
        return SaveArticle::DEFAULT_ACCOUNTS[2500];
    }

    /**
     * The figures the person saw, so that a proposal is made only from them
     * (ADR-081 wave C): any change in a project's figures or rows, the method
     * or the date gives another fingerprint.
     *
     * @param  list<array{project: Project, kind: string, figures: array<string, ?int>, adjustment: int, revenue_account: int, rows: list<array{account: int, debit_ore: int, credit_ore: int}>, note: ?string}>  $lines
     */
    private static function fingerprint(CarbonImmutable $date, FixedPriceMethod $method, array $lines): string
    {
        return hash('sha256', (string) json_encode([
            'date' => $date->toDateString(),
            'method' => $method->value,
            'lines' => array_map(fn (array $line): array => [$line['project']->id, $line['kind'], $line['figures'], $line['rows']], $lines),
        ]));
    }
}

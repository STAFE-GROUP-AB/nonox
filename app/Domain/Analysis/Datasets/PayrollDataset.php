<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Datasets;

use App\Domain\Analysis\AnalysisContext;
use App\Domain\Analysis\Dataset;
use App\Domain\Analysis\Dimension;
use App\Domain\Analysis\DimensionSql;
use App\Domain\Analysis\Measure;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\User;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayslipLine;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Gross pay per cost centre, pay code and month, never per person (ADR-081
 * gap 7, E17; owner decision 17). Only the pay of approved runs, and only the
 * lines that make up the gross pay (not items after tax, nor the employer's
 * own costs).
 *
 * Small and dominated cells are hidden. The data is first grouped in its
 * finest cells, one per pay code, cost centre and month. A cell is hidden when
 * fewer than MIN_PEOPLE people have pay in it (people with a zero amount do not
 * count), or when one person accounts for more than DOMINANCE_PERCENT of it.
 * Every figure shown is made of finest cells that are all shown: a total or a
 * regrouping that contains a hidden cell is hidden too, so a hidden cell's
 * figure cannot be had by subtracting one shown figure from another. The count
 * of people is never shown.
 *
 * What this does not prevent: comparing two periods or two cost centres can
 * still show how one person's pay changed in a cell of three or more, and
 * "Dold" shows that a pay code occurred. The dataset shows nothing beyond what
 * payroll:view already sees per person.
 *
 * Opened only to those who have payroll:view in the company. A member limited
 * to cost centres or projects has no payroll permission and no dataset that
 * does not apply the scope (E1), so aggregated pay stays closed to them.
 * TODO(legal-verify): the numbers three and 80 %, that a group of three can
 * still be informative in a uniform unit, difference over time within a shown
 * cell, that Dold reveals that a pay code occurred, and art. 5.1 c.
 */
final class PayrollDataset implements Dataset
{
    /** TODO(legal-verify): the smallest group whose pay may be shown. */
    public const int MIN_PEOPLE = 3;

    /** The same number as written in the SQL; a test keeps the two equal. */
    public const string MIN_PEOPLE_SQL = '3';

    /** TODO(legal-verify): a finest cell is hidden when one person accounts for more than this share of it. */
    public const int DOMINANCE_PERCENT = 80;

    public const string DOMINANCE_PERCENT_SQL = '80';

    public function key(): string
    {
        return 'payroll';
    }

    public function label(): string
    {
        return __('Lön, sammanräknad');
    }

    public function description(): string
    {
        return __('Bruttolön per lönekod, kostnadsställe och månad, aldrig per person. Små eller dominerade grupper döljs.');
    }

    public function allows(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'payroll:view') && ! DimensionScope::limits($user, $company);
    }

    public function dimensions(): array
    {
        return [
            'pay_code' => new Dimension('pay_code', __('Lönekod'), fn () => new DimensionSql('leaf.pay_code_id', 'leaf.pay_code_label'), fn (): array => PayCode::query()->orderBy('code')->limit(500)->get(['id', 'code', 'name_sv'])
                ->mapWithKeys(fn (PayCode $code): array => [(string) $code->id => "{$code->code} {$code->name_sv}"])->all()),
            'cost_center' => new Dimension('cost_center', __('Kostnadsställe'), fn () => new DimensionSql('leaf.cost_center_id', 'leaf.cost_center_label'), fn (): array => ['0' => __('Ingen enhet')] + CostCenter::query()->orderBy('code')->limit(500)->get(['id', 'code', 'name'])
                ->mapWithKeys(fn (CostCenter $unit): array => [(string) $unit->id => "{$unit->code} {$unit->name}"])->all(), true),
            'period' => new Dimension('period', __('Månad'), fn () => new DimensionSql('leaf.period', 'leaf.period'), fn (): array => []),
        ];
    }

    public function measures(): array
    {
        return [
            'gross' => new Measure(
                'gross',
                __('Bruttolön'),
                Measure::MONEY,
                fn ($f) => "CASE WHEN COALESCE(BOOL_OR(leaf.hide) FILTER (WHERE {$f}), false) THEN NULL ELSE SUM(leaf.amount) FILTER (WHERE {$f}) END",
                fn ($f) => "COALESCE(BOOL_OR(leaf.hide) FILTER (WHERE {$f}), false)",
            ),
        ];
    }

    public function defaultRows(): array
    {
        return ['cost_center'];
    }

    public function dateExpression(): string
    {
        return 'leaf.period_date';
    }

    public function listRoute(): ?string
    {
        return null;
    }

    public function drillable(): bool
    {
        return false;
    }

    public function query(AnalysisContext $context): Builder
    {
        $statuses = [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED];

        $leaf = PayslipLine::query()->toBase()
            ->join('payslips', fn (JoinClause $join) => $join->on('payslips.id', '=', 'payslip_lines.payslip_id')->on('payslips.company_id', '=', 'payslip_lines.company_id'))
            ->join('payroll_runs', fn (JoinClause $join) => $join->on('payroll_runs.id', '=', 'payslips.payroll_run_id')->on('payroll_runs.company_id', '=', 'payslips.company_id'))
            ->join('employments', fn (JoinClause $join) => $join->on('employments.id', '=', 'payslips.employment_id')->on('employments.company_id', '=', 'payslips.company_id'))
            ->join('pay_codes', fn (JoinClause $join) => $join->on('pay_codes.id', '=', 'payslip_lines.pay_code_id')->on('pay_codes.company_id', '=', 'payslip_lines.company_id'))
            ->leftJoin('cost_centers', fn (JoinClause $join) => $join->on('cost_centers.id', '=', DB::raw('COALESCE(payslip_lines.cost_center_id, employments.cost_center_id)'))->on('cost_centers.company_id', '=', 'payslip_lines.company_id'))
            ->where('payslip_lines.company_id', $context->company->id)
            ->whereIn('payroll_runs.status', $statuses)
            ->whereNotIn('payslip_lines.tax_effect', [PayslipLineResult::NET, PayslipLineResult::EMPLOYER])
            ->selectRaw("pay_codes.id AS pay_code_id, pay_codes.code || ' ' || pay_codes.name_sv AS pay_code_label")
            ->selectRaw("COALESCE(cost_centers.id, 0) AS cost_center_id, COALESCE(cost_centers.code || ' ' || cost_centers.name, 'Ingen enhet') AS cost_center_label")
            ->selectRaw("payroll_runs.period AS period, to_date(payroll_runs.period || '-01', 'YYYY-MM-DD') AS period_date")
            ->selectRaw('payslips.employee_id AS employee_id, SUM(payslip_lines.amount_ore) AS person_amount')
            ->groupByRaw('pay_codes.id, pay_codes.code, pay_codes.name_sv, cost_centers.id, cost_centers.code, cost_centers.name, payroll_runs.period, payslips.employee_id');

        // The finest cell: one per pay code, cost centre and month, hidden when it has fewer than MIN_PEOPLE people
        // with pay in it, or when one person accounts for more than DOMINANCE_PERCENT of it.
        $hidden = 'COUNT(*) FILTER (WHERE person.person_amount <> 0) < '.self::MIN_PEOPLE_SQL
            .' OR MAX(ABS(person.person_amount)) * 100 > '.self::DOMINANCE_PERCENT_SQL.' * SUM(ABS(person.person_amount))';

        $cells = DB::query()->fromSub($leaf, 'person')
            ->selectRaw('person.pay_code_id, person.pay_code_label, person.cost_center_id, person.cost_center_label, person.period, person.period_date')
            ->selectRaw("SUM(person.person_amount) AS amount, ({$hidden}) AS hide")
            ->groupByRaw('person.pay_code_id, person.pay_code_label, person.cost_center_id, person.cost_center_label, person.period, person.period_date');

        return DB::query()->fromSub($cells, 'leaf');
    }

    public function detail(AnalysisContext $context, Builder $query): array
    {
        return ['columns' => [], 'rows' => []];
    }
}

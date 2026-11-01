<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Search;

use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;
use Carbon\CarbonImmutable;

/**
 * Lönebesked (Lön): by the employee's name and the pay date ("oktober",
 * "lönebesked Anna"). Opens the payroll run the payslip belongs to.
 */
final class PayslipsSearchProvider extends DatabaseSearchProvider
{
    public const string KEY = 'payslips';

    private const array COLUMNS = [EmployeesSearchProvider::NAME];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Lönebesked');
    }

    public function module(): string
    {
        return PayrollModule::KEY;
    }

    public function order(): int
    {
        return 41;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->can('payroll:view');
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        // Payslips are found by name and month, never by a bare number or amount.
        if ($query->number !== null || $query->impliedAmount !== null || ! $this->wants($query, dates: true)) {
            return null;
        }

        if (trim($query->text) === '' && ! $query->hasDate() && ! in_array(self::KEY, $query->types, true)) {
            return null;
        }

        $builder = Payslip::query()
            ->where('payslips.company_id', $context->company->id)
            ->join('employees', 'employees.id', '=', 'payslips.employee_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->select('payslips.*', 'employees.first_name', 'employees.last_name', 'payroll_runs.payment_date', 'payroll_runs.period');

        $this->whereText($builder, self::COLUMNS, $query);
        $this->selectRank($builder, self::COLUMNS, $query);
        $this->whereDate($builder, 'payroll_runs.payment_date', $query);

        [$payslips, $total] = $this->fetch($builder, 'payroll_runs.payment_date', $limit);

        return $this->group(
            $payslips->map(fn (Payslip $payslip): SearchResult => $this->result($payslip)->withScore(self::rank($payslip)))->all(),
            $total,
            route('payroll.runs.index'),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        $payslip = ctype_digit($id) ? Payslip::query()
            ->join('employees', 'employees.id', '=', 'payslips.employee_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->select('payslips.*', 'employees.first_name', 'employees.last_name', 'payroll_runs.payment_date', 'payroll_runs.period')
            ->find((int) $id) : null;

        return $payslip instanceof Payslip ? $this->result($payslip) : null;
    }

    private function result(Payslip $payslip): SearchResult
    {
        $paid = CarbonImmutable::parse((string) $payslip->getAttribute('payment_date'));

        return new SearchResult(
            type: self::KEY,
            id: (string) $payslip->id,
            title: __('Lönebesked :name', ['name' => trim($payslip->getAttribute('first_name').' '.$payslip->getAttribute('last_name'))]),
            url: route('payroll.runs.show', $payslip->payroll_run_id),
            subtitle: __('Utbetalning :date', ['date' => $paid->toDateString()]),
            meta: $payslip->net_ore->format(),
            icon: 'payslip',
        );
    }
}

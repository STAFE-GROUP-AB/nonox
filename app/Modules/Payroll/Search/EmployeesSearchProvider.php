<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Search;

use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\PayrollModule;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\PersonalNumberSuffix;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;

/**
 * Anställda (Lön): by name, employee number and email. The personal
 * number is only matched on its last four digits, only for those with
 * payroll:view-sensitive, and is never shown.
 */
final class EmployeesSearchProvider extends DatabaseSearchProvider
{
    public const string KEY = 'employees';

    /** The same expression as the trigram index on employees. */
    public const string NAME = "(coalesce(employees.first_name, '') || ' ' || coalesce(employees.last_name, ''))";

    private const array COLUMNS = [self::NAME, 'employees.email'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Anställda');
    }

    public function module(): string
    {
        return PayrollModule::KEY;
    }

    public function order(): int
    {
        return 40;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->can('payroll:view');
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if (! $this->wants($query)) {
            return null;
        }

        $builder = Employee::query()->where('employees.company_id', $context->company->id)->select('employees.*');
        $identifiers = [];
        $suffix = $context->can('payroll:view-sensitive') ? PersonalNumberSuffix::of($query) : null;
        $bySuffix = [];

        if ($query->number !== null) {
            $identifiers[] = ['employees.employee_number = ?', [$query->number]];
        }

        if ($suffix !== null) {
            $bySuffix = PersonalNumberSuffix::matching(Employee::query()->where('company_id', $context->company->id), $suffix);

            if ($bySuffix !== []) {
                $identifiers[] = ['employees.id = ANY(CAST(? AS bigint[]))', ['{'.implode(',', $bySuffix).'}'], 900];
            }
        }

        $text = $query->number === null ? $query : new SearchQuery($query->raw);

        if ($query->number !== null && $identifiers === []) {
            return null;
        }

        $this->whereText($builder, self::COLUMNS, $text, $this->anyOf($identifiers));
        $this->selectRank($builder, self::COLUMNS, $text, $identifiers);

        [$employees, $total] = $this->fetch($builder, 'employees.updated_at', $limit);

        return $this->group(
            $employees->map(fn (Employee $employee): SearchResult => $this->result($employee, in_array($employee->id, $bySuffix, true) ? $suffix : null)->withScore(self::rank($employee)))->all(),
            $total,
            route('payroll.employees'),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        $employee = ctype_digit($id) ? Employee::query()->find((int) $id) : null;

        return $employee instanceof Employee ? $this->result($employee) : null;
    }

    /** Absence and outlay requests waiting for a decision, as in the sidebar's Ansökningar. */
    public function todo(SearchContext $context): array
    {
        if (! $context->can('payroll:approve')) {
            return [];
        }

        $waiting = Absence::query()->where('status', 'REQUESTED')->count() + Expense::query()->where('status', 'SUBMITTED')->count();

        if ($waiting === 0) {
            return [];
        }

        return [new SearchResult(
            type: 'todo',
            id: 'payroll-requests',
            title: trans_choice(':count ansökan om ledighet eller utlägg att hantera|:count ansökningar om ledighet eller utlägg att hantera', $waiting, ['count' => $waiting]),
            url: route('payroll.requests'),
            icon: 'person',
            remember: false,
        )];
    }

    private function result(Employee $employee, ?string $suffix = null): SearchResult
    {
        return new SearchResult(
            type: self::KEY,
            id: (string) $employee->id,
            title: trim("{$employee->first_name} {$employee->last_name}"),
            url: route('payroll.employees', ['redigera' => $employee->id]),
            subtitle: self::join(
                __('Anställd :number', ['number' => $employee->employee_number]),
                $suffix === null ? null : PersonalNumberSuffix::label($suffix),
            ),
            badge: self::when($employee->status === 'TERMINATED', __('Avslutad')),
            icon: 'person',
        );
    }
}

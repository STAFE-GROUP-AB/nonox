<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Datasets;

use App\Domain\Analysis\AnalysisContext;
use App\Domain\Analysis\Dataset;
use App\Domain\Analysis\Dimension;
use App\Domain\Analysis\DimensionSql;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The common part of the datasets whose rows carry an account, a cost centre
 * and a project (ledger rows, budget lines, supplier invoice rows): the
 * dimensions, and the one place where a member limited to cost centres or
 * projects is held inside the scope (ADR-081 gap 10).
 *
 * For a limited member: a row is read only when its cost centre or its project
 * is one of the member's (the Resultatrapport's rule), the personnel accounts
 * 7000-7699 are never read (owner decision 17, as in E7), and a cost centre or
 * project outside the scope is shown as "Annan enhet" with neither name nor
 * number, so no choice of grouping or filter shows another unit's figure.
 */
abstract class DimensionedRowsDataset implements Dataset
{
    /** The personnel accounts, as in ProjectResults (E7). */
    public const int PERSONNEL_FROM = 7000;

    public const int PERSONNEL_TO = 7699;

    /** Most values a filter picker lists. */
    private const int OPTION_LIMIT = 500;

    private const array CLASSES = [
        1 => 'Klass 1 Tillgångar',
        2 => 'Klass 2 Eget kapital och skulder',
        3 => 'Klass 3 Intäkter',
        4 => 'Klass 4 Varor och material',
        5 => 'Klass 5 Övriga externa kostnader',
        6 => 'Klass 6 Övriga externa kostnader',
        7 => 'Klass 7 Personal och avskrivningar',
        8 => 'Klass 8 Finansiellt och bokslut',
    ];

    /**
     * The table whose rows carry account_id, cost_center_id and project_id.
     *
     * @return literal-string
     */
    abstract protected function rowTable(): string;

    /** The permission the dataset needs. */
    abstract protected function permission(): string;

    public function allows(User $user, Company $company): bool
    {
        if (! $user->hasTeamPermission($company, $this->permission())) {
            return false;
        }

        return ! DimensionScope::limits($user, $company) || $this->appliesScope();
    }

    /** Whether the dataset holds a limited member inside the scope; this family does. */
    protected function appliesScope(): bool
    {
        return true;
    }

    public function drillable(): bool
    {
        return true;
    }

    /** The joins to accounts, cost centres and projects, left joins on the same company. */
    protected function joinDimensions(Builder $query): Builder
    {
        $table = $this->rowTable();

        return $query
            ->join('accounts', fn (JoinClause $join) => $join->on('accounts.id', '=', "{$table}.account_id")->on('accounts.company_id', '=', "{$table}.company_id"))
            ->leftJoin('cost_centers', fn (JoinClause $join) => $join->on('cost_centers.id', '=', "{$table}.cost_center_id")->on('cost_centers.company_id', '=', "{$table}.company_id"))
            ->leftJoin('projects', fn (JoinClause $join) => $join->on('projects.id', '=', "{$table}.project_id")->on('projects.company_id', '=', "{$table}.company_id"));
    }

    /** Holds a limited member inside the scope; leaves an unlimited member's query alone. */
    protected function applyScope(Builder $query, AnalysisContext $context): Builder
    {
        if (! $context->scope->restricted) {
            return $query;
        }

        $table = $this->rowTable();
        $costCenters = $context->scope->costCenterIds;
        $projects = $context->scope->projectIds;

        $query->where(function (Builder $inside) use ($table, $costCenters, $projects): void {
            $inside->whereRaw('false');

            if ($costCenters !== []) {
                $inside->orWhereIn("{$table}.cost_center_id", $costCenters);
            }

            if ($projects !== []) {
                $inside->orWhereIn("{$table}.project_id", $projects);
            }
        });

        return $query->whereNotBetween('accounts.number', [self::PERSONNEL_FROM, self::PERSONNEL_TO]);
    }

    /** @return array<string, Dimension> */
    protected function accountDimensions(): array
    {
        $accounts = fn (AnalysisContext $context): array => $this->accountOptions($context);

        return [
            'account' => new Dimension('account', __('Konto'), fn () => new DimensionSql('accounts.number', "accounts.number || ' ' || accounts.name"), $accounts),
            'account_group' => new Dimension('account_group', __('Kontogrupp'), fn () => new DimensionSql('accounts.number / 100', "(accounts.number / 100)::text || 'xx'"), fn (): array => $this->accountGroupOptions()),
            'account_class' => new Dimension('account_class', __('Kontoklass'), fn () => new DimensionSql('accounts.number / 1000', "CASE accounts.number / 1000 WHEN 1 THEN 'Klass 1 Tillgångar' WHEN 2 THEN 'Klass 2 Eget kapital och skulder' WHEN 3 THEN 'Klass 3 Intäkter' WHEN 4 THEN 'Klass 4 Varor och material' WHEN 5 THEN 'Klass 5 Övriga externa kostnader' WHEN 6 THEN 'Klass 6 Övriga externa kostnader' WHEN 7 THEN 'Klass 7 Personal och avskrivningar' WHEN 8 THEN 'Klass 8 Finansiellt och bokslut' ELSE 'Övriga' END"), fn (): array => array_map(fn (string $label): string => __($label), self::CLASSES), true),
        ];
    }

    protected function costCenterDimension(): Dimension
    {
        return $this->unitDimension('cost_center', __('Kostnadsställe'), 'cost_center_id', "cost_centers.code || ' ' || cost_centers.name", 'Ingen enhet', 'costCenterIds', CostCenter::class);
    }

    protected function projectDimension(): Dimension
    {
        return $this->unitDimension('project', __('Projekt'), 'project_id', "projects.code || ' ' || projects.name", 'Inget projekt', 'projectIds', Project::class);
    }

    /**
     * A cost centre or project dimension. Without a scope: its code and name,
     * or the empty label. With one: the member's own ones as they are, no unit
     * as 0, and any other as -1 "Annan enhet" with no name. The member's ids
     * are one bound parameter, an array literal.
     *
     * @param  class-string<CostCenter|Project>  $model
     * @param  literal-string  $column
     * @param  literal-string  $name
     * @param  literal-string  $none
     */
    private function unitDimension(string $key, string $label, string $column, string $name, string $none, string $scopeIds, string $model): Dimension
    {
        $table = $this->rowTable();
        $column = "{$table}.{$column}";

        $sql = function (AnalysisContext $context) use ($column, $name, $none, $scopeIds): DimensionSql {
            if (! $context->scope->restricted) {
                return new DimensionSql("COALESCE({$column}, 0)", "COALESCE({$name}, '{$none}')");
            }

            $ids = '{'.implode(',', array_map('intval', $context->scope->{$scopeIds})).'}';

            return new DimensionSql(
                "CASE WHEN {$column} IS NULL THEN 0 WHEN {$column} = ANY (CAST(? AS bigint[])) THEN {$column} ELSE -1 END",
                "CASE WHEN {$column} IS NULL THEN '{$none}' WHEN {$column} = ANY (CAST(? AS bigint[])) THEN {$name} ELSE 'Annan enhet' END",
                $ids,
            );
        };

        $options = function (AnalysisContext $context) use ($model, $scopeIds, $none): array {
            $query = $model::query()->orderBy('code')->limit(self::OPTION_LIMIT);

            if ($context->scope->restricted) {
                $query->whereIn('id', $context->scope->{$scopeIds});
            }

            $options = $query->get(['id', 'code', 'name'])->mapWithKeys(fn ($unit): array => [(string) $unit->id => "{$unit->code} {$unit->name}"])->all();

            return ['0' => __($none)] + $options + ($context->scope->restricted ? ['-1' => __('Annan enhet')] : []);
        };

        return new Dimension($key, $label, $sql, $options, true);
    }

    /** @return array<int|string, string> */
    private function accountOptions(AnalysisContext $context): array
    {
        return Account::query()
            ->when($context->scope->restricted, fn ($query) => $query->whereNotBetween('number', [self::PERSONNEL_FROM, self::PERSONNEL_TO]))
            ->orderBy('number')
            ->limit(self::OPTION_LIMIT * 4)
            ->get(['number', 'name'])
            ->mapWithKeys(fn (Account $account): array => [(string) $account->number => "{$account->number} {$account->name}"])
            ->all();
    }

    /** @return array<int|string, string> */
    private function accountGroupOptions(): array
    {
        $options = [];

        for ($group = 10; $group <= 89; $group++) {
            $options[(string) $group] = "{$group}xx";
        }

        return $options;
    }

    /**
     * The month of a date column, as "YYYY-MM".
     *
     * @param  literal-string  $dateColumn
     */
    protected function periodDimension(string $dateColumn): Dimension
    {
        return new Dimension('period', __('Månad'), fn () => new DimensionSql("to_char({$dateColumn}, 'YYYY-MM')", "to_char({$dateColumn}, 'YYYY-MM')"), fn (): array => []);
    }
}

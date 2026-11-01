<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Models\Accrual;
use App\Models\CostCenter;
use App\Models\FixedAsset;
use App\Models\Project;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Model;

/**
 * Small registers found by code or number and name, each opening its list
 * page: anläggningar, periodiseringar, projekt and kostnadsställen.
 */
final class NamedRecordsProvider extends DatabaseSearchProvider
{
    /**
     * @param  string  $label  untranslated: translated when shown, in the user's language
     * @param  class-string<Model>  $model
     * @param  literal-string  $table
     * @param  literal-string  $identifier  the column holding the number or code
     * @param  ?string  $permission  the permission the list page asks for; null to use the model's viewAny policy
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $singular,
        private readonly int $order,
        private readonly string $model,
        private readonly string $table,
        private readonly string $identifier,
        private readonly string $route,
        private readonly string $icon,
        private readonly ?string $permission = null,
    ) {}

    public static function fixedAssets(): self
    {
        return new self('assets', 'Anläggningar', 'Anläggning', 30, FixedAsset::class, 'fixed_assets', 'number', 'accounting.fixedAssets', 'asset', 'fiscal-years:manage');
    }

    public static function accruals(): self
    {
        return new self('accruals', 'Periodiseringar', 'Periodisering', 31, Accrual::class, 'accruals', 'number', 'accounting.accruals', 'accrual', 'fiscal-years:manage');
    }

    public static function projects(): self
    {
        return new self('projects', 'Projekt', 'Projekt', 32, Project::class, 'projects', 'code', 'accounting.projects', 'project');
    }

    public static function costCenters(): self
    {
        return new self('cost-centers', 'Kostnadsställen', 'Kostnadsställe', 33, CostCenter::class, 'cost_centers', 'code', 'accounting.costCenters', 'cost-center');
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return __($this->label);
    }

    public function order(): int
    {
        return $this->order;
    }

    public function authorize(SearchContext $context): bool
    {
        return $this->permission === null ? $context->allows('viewAny', $this->model) : $context->can($this->permission);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if (! $this->wants($query)) {
            return null;
        }

        $table = $this->table;
        $builder = $this->model::query()->where("{$table}.company_id", $context->company->id)->select("{$table}.*");
        $identifiers = trim($query->text) === '' ? [] : [["lower(CAST({$table}.{$this->identifier} AS text)) = lower(?)", [trim($query->text)]]];

        $this->whereText($builder, ["{$table}.name"], $query, $this->anyOf($identifiers));
        $this->selectRank($builder, ["{$table}.name"], $query, $identifiers);

        [$records, $total] = $this->fetch($builder, "{$table}.updated_at", $limit);

        return $this->group(
            $records->map(fn (Model $record): SearchResult => $this->result($record)->withScore(self::rank($record)))->all(),
            $total,
            route($this->route),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        $record = ctype_digit($id) ? $this->model::query()->find((int) $id) : null;

        return $record instanceof Model ? $this->result($record) : null;
    }

    private function result(Model $record): SearchResult
    {
        $identifier = (string) $record->getAttribute($this->identifier);

        return new SearchResult(
            type: $this->key,
            id: (string) $record->getKey(),
            title: (string) $record->getAttribute('name'),
            url: route($this->route),
            subtitle: trim(__($this->singular).' '.$identifier),
            badge: self::when($record->getAttribute('is_active') === false, __('Inaktiv')),
            icon: $this->icon,
        );
    }
}

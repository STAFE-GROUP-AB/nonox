<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing\Concerns;

use App\Domain\Units\UnitRegister;
use App\Domain\Vat\SalesVatRules;
use App\Models\Account;
use App\Models\Article;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The rows of an invoice, quote or order being written. Adding rows,
 * filling them from articles and the running totals happen in the browser
 * (resources/js/sales-rows.js); the rows reach the server when the document
 * is saved, and the server calculates everything again. A row's unit is
 * chosen from the company's unit register (ADR-071 §12.9); an archived
 * unit is offered only on the rows that already had it. A row's cost
 * centre and project (ADR-081 gap 6) are offered when the company has any;
 * a new row takes the customer's defaults and an article row the article's,
 * in the browser, and a project's default cost centre fills an empty one.
 *
 * @mixin Component
 */
trait EditsSalesRows
{
    /** @var array<int, array<string, string>> */
    public array $rows = [];

    /** @var list<int> the units the document's rows had when it was opened: still offered, even when archived */
    #[Locked]
    public array $keptUnitIds = [];

    /**
     * The active articles for the rows, with their revenue account, so the
     * browser can warn when a row's VAT rate does not fit it (#62).
     *
     * @return Collection<int, Article>
     */
    protected function rowArticles(): Collection
    {
        return Article::query()->where('is_active', true)->with('account:id,number')->orderBy('number')->get(['number', 'name', 'unit', 'stock_unit_id', 'sales_unit_id', 'price_ore', 'vat_rate', 'account_id', 'default_cost_center_id', 'default_project_id']);
    }

    /**
     * The articles for the rows, the units the rows offer, the one a new row
     * starts with, and whether the person may open the unit register. An
     * article's own unit is offered even when it has been archived, as on
     * the article itself.
     *
     * @return array{articles: Collection<int, Article>, units: Collection<int, Unit>, defaultUnitId: int, canManageUnits: bool, rowDimensions: array{show: bool, costCenters: array<string, string>, projects: array<string, array{label: string, cost_center: string}>, customers: array<int, array{cost_center: string, project: string}>}}
     */
    protected function rowData(): array
    {
        $articles = $this->rowArticles()->load(['defaultCostCenter:id,code', 'defaultProject:id,code']);
        $register = UnitRegister::load();
        $user = Auth::user();
        $company = $user instanceof User ? $user->currentCompany() : null;

        return [
            'articles' => $articles,
            'units' => $register->choices([...$this->keptUnitIds, ...self::articleUnitIds($articles)]),
            'defaultUnitId' => $register->default()->id,
            'canManageUnits' => $user instanceof User && $company !== null && $user->can('create', [Article::class, $company]),
            'rowDimensions' => $this->rowDimensions(),
        ];
    }

    /** Whether the document's rows carry a cost centre and project (a recurring invoice's do not). */
    protected function rowDimensionsEnabled(): bool
    {
        return true;
    }

    /**
     * The cost centres and projects the rows offer, by code: the active ones
     * and any the rows already have; each project with its default cost
     * centre; and the customers with default dimensions. Shown only when
     * there is something to choose or the rows already use it. Four queries.
     *
     * @return array{show: bool, costCenters: array<string, string>, projects: array<string, array{label: string, cost_center: string}>, customers: array<int, array{cost_center: string, project: string}>}
     */
    protected function rowDimensions(): array
    {
        $none = ['show' => false, 'costCenters' => [], 'projects' => [], 'customers' => []];

        if (! $this->rowDimensionsEnabled()) {
            return $none;
        }

        $usedCostCenters = array_values(array_filter(array_map(fn (array $row): string => (string) ($row['cost_center'] ?? ''), $this->rows)));
        $usedProjects = array_values(array_filter(array_map(fn (array $row): string => (string) ($row['project'] ?? ''), $this->rows)));

        $costCenters = CostCenter::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('code', $usedCostCenters))
            ->orderBy('code')->get(['id', 'code', 'name', 'is_active']);
        $projects = Project::query()
            ->with('defaultCostCenter:id,code')
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('code', $usedProjects))
            ->orderBy('code')->get(['id', 'code', 'name', 'is_active', 'default_cost_center_id']);

        if ($costCenters->isEmpty() && $projects->isEmpty()) {
            return $none;
        }

        $label = fn (CostCenter|Project $object): string => $object->is_active ? $object->label() : $object->label().' ('.__('inaktivt').')';

        return [
            'show' => true,
            'costCenters' => $costCenters->mapWithKeys(fn (CostCenter $object): array => [$object->code => $label($object)])->all(),
            'projects' => $projects->mapWithKeys(fn (Project $object): array => [$object->code => [
                'label' => $label($object),
                'cost_center' => (string) $object->defaultCostCenter?->code,
            ]])->all(),
            'customers' => Customer::query()
                ->with(['defaultCostCenter:id,code', 'defaultProject:id,code'])
                ->where(fn ($query) => $query->whereNotNull('default_cost_center_id')->orWhereNotNull('default_project_id'))
                ->get(['id', 'default_cost_center_id', 'default_project_id'])
                ->mapWithKeys(fn (Customer $customer): array => [$customer->id => [
                    'cost_center' => (string) $customer->defaultCostCenter?->code,
                    'project' => (string) $customer->defaultProject?->code,
                ]])->all(),
        ];
    }

    /**
     * The revenue accounts' VAT rules for the browser (SalesVatRules).
     *
     * @return array<int, array{rates: list<int>, vat: ?list<int>}>
     */
    protected function salesVatRules(): array
    {
        return SalesVatRules::forAccounts(Account::query()->whereBetween('number', [3000, 3999])->get(['id', 'number', 'vat_box']));
    }

    /**
     * The filled rows as input for the save action.
     *
     * @return list<array<string, mixed>>
     */
    protected function rowInput(): array
    {
        $filled = $this->filledRows();
        $numbers = array_values(array_filter(array_map(fn (array $row): string => trim((string) ($row['article'] ?? '')), $filled)));
        $articles = $numbers === [] ? new Collection : Article::query()->whereIn('number', $numbers)->get(['id', 'stock_unit_id', 'sales_unit_id']);

        UnitRegister::load()->assertSelectable(
            collect($filled)->mapWithKeys(fn (array $row, int $index): array => ["rows.{$index}.unit_id" => $row['unit_id'] ?? null])->all(),
            [...$this->keptUnitIds, ...self::articleUnitIds($articles)],
        );

        return array_map(fn (array $row): array => [
            ...$row,
            'vat_rate' => (int) ($row['vat_rate'] ?? 2500),
            'account' => ($row['account'] ?? '') === '' ? null : (int) $row['account'],
        ], $filled);
    }

    /**
     * The units the articles are sold in: allowed on their rows even when archived.
     *
     * @param  Collection<int, Article>  $articles
     * @return list<int>
     */
    private static function articleUnitIds(Collection $articles): array
    {
        return array_values(array_unique(array_filter($articles->map(fn (Article $article): ?int => $article->sales_unit_id ?? $article->stock_unit_id)->all())));
    }

    /**
     * A row saved before the unit register (a row of an issued invoice, now
     * credited) gets the unit its text names.
     *
     * @param  iterable<object>  $rows  invoice or quote/order rows with their account
     * @return array<int, array<string, string>>
     */
    protected function rowsFrom(iterable $rows): array
    {
        $result = [];
        $register = UnitRegister::load();

        foreach ($rows as $row) {
            $input = method_exists($row, 'asInput') ? $row->asInput() : [];

            if (($input['unit_id'] ?? '') === '' && is_string($input['unit'] ?? null)) {
                $input['unit_id'] = (string) $register->match($input['unit'])?->id;
            }

            $result[] = array_map(fn (mixed $value): string => is_bool($value) ? ($value ? '1' : '') : (string) $value, ['article' => '', ...$input]);

            if (($input['unit_id'] ?? '') !== '') {
                $this->keptUnitIds[] = (int) $input['unit_id'];
            }
        }

        $this->keptUnitIds = array_values(array_unique($this->keptUnitIds));

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function filledRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $row): bool => trim((string) ($row['description'] ?? '')) !== '' || trim((string) ($row['price'] ?? '')) !== ''));
    }

    /** @return array<string, string> */
    private function blankRow(): array
    {
        return ['article' => '', 'description' => '', 'quantity' => '1', 'unit_id' => (string) UnitRegister::load()->default()->id, 'price' => '', 'discount' => '', 'vat_rate' => '2500', 'account' => '', 'cost_center' => '', 'project' => ''];
    }
}

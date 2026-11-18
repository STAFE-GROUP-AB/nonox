<?php

declare(strict_types=1);

namespace App\Livewire\Analysis;

use App\Domain\Analysis\AnalysisContext;
use App\Domain\Analysis\AnalysisDefinition;
use App\Domain\Analysis\AnalysisRunner;
use App\Domain\Analysis\Dataset;
use App\Domain\Analysis\DatasetCatalogue;
use App\Domain\Analysis\Dimension;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The analysis view (ADR-081 gap 7, E17): a dataset from the fixed catalogue,
 * rows, columns (a pivot), filters, a period and a comparison, with totals,
 * sorting and a drill-down to the records behind a cell. All choices are keys
 * from the catalogue, kept in the URL (AGENTS.md §11.3), and every one is
 * checked against the dataset on every render, so nothing typed into the URL
 * reaches a query.
 *
 * A member limited to cost centres or projects gets only the datasets that
 * hold them inside their scope, and each dataset leaves out what they may not
 * see before anything is grouped (ADR-081 gap 10, DimensionedRowsDataset).
 * A fixed number of queries, whatever the amount of data: the pivot is one
 * query, a filter picker is one query per chosen filter, and a drill-down one.
 */
class AnalysisPage extends Component
{
    #[Url(as: 'mangd', except: '')]
    public string $source = '';

    /** @var list<string> */
    #[Url(as: 'rader')]
    public array $rows = [];

    #[Url(as: 'kolumn', except: '')]
    public string $column = '';

    #[Url(as: 'matt', except: '')]
    public string $metric = '';

    #[Url(as: 'fran', except: '')]
    public string $from = '';

    #[Url(as: 'till', except: '')]
    public string $to = '';

    #[Url(as: 'jamfor', except: false)]
    public bool $compare = false;

    #[Url(as: 'sortera', except: 'label')]
    public string $sort = 'label';

    #[Url(as: 'filter1', except: '')]
    public string $filterDimension1 = '';

    /** @var list<string> */
    #[Url(as: 'varden1')]
    public array $filterValues1 = [];

    #[Url(as: 'filter2', except: '')]
    public string $filterDimension2 = '';

    /** @var list<string> */
    #[Url(as: 'varden2')]
    public array $filterValues2 = [];

    public ?string $drillRow = null;

    public ?string $drillColumn = null;

    public function mount(): void
    {
        $user = $this->user();
        $company = $this->company();

        if (! $user->hasTeamPermission($company, 'analysis:view')) {
            abort(DimensionScope::limits($user, $company) ? 404 : 403);
        }

        $datasets = DatasetCatalogue::for($user, $company);

        if ($datasets === []) {
            return;
        }

        $dataset = $datasets[$this->source] ?? array_values($datasets)[0];
        $definition = AnalysisDefinition::normalise($dataset, $this->input());
        $this->source = $dataset->key();
        $this->rows = $definition->rows;
        $this->metric = $definition->measure;
        $this->from = $definition->from->format('Y-m');
        $this->to = $definition->to->format('Y-m');
    }

    public function updated(string $property): void
    {
        if ($property !== 'drillRow' && $property !== 'drillColumn') {
            $this->closeDrill();
        }

        if ($property === 'source') {
            $this->rows = [];
            $this->column = '';
            $this->metric = '';
            $this->filterDimension1 = $this->filterDimension2 = '';
            $this->filterValues1 = $this->filterValues2 = [];
        }

        if ($property === 'filterDimension1') {
            $this->filterValues1 = [];
        }

        if ($property === 'filterDimension2') {
            $this->filterValues2 = [];
        }
    }

    public function drill(string $row, string $column): void
    {
        $this->drillRow = $row;
        $this->drillColumn = $column;
    }

    public function closeDrill(): void
    {
        $this->drillRow = $this->drillColumn = null;
    }

    public function render(AnalysisRunner $runner): View
    {
        $user = $this->user();
        $company = $this->company();
        $datasets = DatasetCatalogue::for($user, $company);

        if ($datasets === []) {
            return view('livewire.analysis.analysis-page', ['datasets' => [], 'dataset' => null]);
        }

        $dataset = $datasets[$this->source] ?? array_values($datasets)[0];
        $definition = AnalysisDefinition::normalise($dataset, $this->input());
        $context = new AnalysisContext($company, $user, DimensionScope::for($user, $company), $definition->from, $definition->periodEnd(), $definition->compare);
        $dimensions = $dataset->dimensions();

        $drill = null;

        if ($this->drillRow !== null && $this->drillColumn !== null) {
            $drill = $runner->drill($dataset, $definition, $context, $this->drillRow, $this->drillColumn);
        }

        return view('livewire.analysis.analysis-page', [
            'datasets' => $datasets,
            'dataset' => $dataset,
            'definition' => $definition,
            'dimensions' => $dimensions,
            'measures' => $dataset->measures(),
            'measure' => $dataset->measures()[$definition->measure],
            'result' => $runner->run($dataset, $definition, $context),
            'filterOptions' => $this->filterOptions($dimensions, $definition, $context),
            'drill' => $drill,
            'drillLimit' => AnalysisRunner::DRILL_LIMIT,
            'limited' => $context->scope->restricted,
        ]);
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $filters = [];

        foreach ([[$this->filterDimension1, $this->filterValues1], [$this->filterDimension2, $this->filterValues2]] as [$dimension, $values]) {
            if ($dimension !== '' && $values !== []) {
                $filters[$dimension] = $values;
            }
        }

        return [
            'rows' => $this->rows,
            'column' => $this->column,
            'measure' => $this->metric,
            'from' => $this->from,
            'to' => $this->to,
            'compare' => $this->compare,
            'filters' => $filters,
            'sort' => $this->sort,
        ];
    }

    /**
     * What each of the two filter pickers may choose: read only for a picker
     * that has a dimension, and limited to what the member may see.
     *
     * @param  array<string, Dimension>  $dimensions
     * @return array{1: array<int|string, string>, 2: array<int|string, string>}
     */
    private function filterOptions(array $dimensions, AnalysisDefinition $definition, AnalysisContext $context): array
    {
        $options = fn (string $key): array => $key !== '' && $key !== 'period' && isset($dimensions[$key]) ? ($dimensions[$key]->options)($context) : [];

        return [1 => $options($this->filterDimension1), 2 => $options($this->filterDimension2)];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}

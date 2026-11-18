<?php

declare(strict_types=1);

namespace App\Livewire\Analysis;

use App\Actions\Analysis\DeleteReport;
use App\Actions\Analysis\PinReport;
use App\Actions\Analysis\SaveReport;
use App\Domain\Analysis\AnalysisContext;
use App\Domain\Analysis\AnalysisDefinition;
use App\Domain\Analysis\AnalysisRunner;
use App\Domain\Analysis\Dataset;
use App\Domain\Analysis\DatasetCatalogue;
use App\Domain\Analysis\Dimension;
use App\Domain\Analysis\Export\ReportFiles;
use App\Domain\Analysis\Export\ReportTable;
use App\Domain\Analysis\Reports\ReportVisibility;
use App\Domain\Analysis\Reports\SavedReports;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\DimensionScope;
use App\Support\WorkQuota;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    use ShowsToasts;

    /** The saved report the choices came from (ADR-081 E18); only a pointer, re-checked on every use. */
    #[Url(as: 'rapport', except: '')]
    public string $reportId = '';

    public string $reportName = '';

    public string $reportVisibility = 'PRIVATE';

    public bool $reportRolling = false;

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

        if ($this->reportId !== '' && $this->source === '') {
            $this->loadReport($datasets);
        }

        $dataset = $datasets[$this->source] ?? array_values($datasets)[0];
        $definition = AnalysisDefinition::normalise($dataset, $this->input());
        $this->source = $dataset->key();
        $this->rows = $definition->rows;
        $this->metric = $definition->measure;
        $this->from = $definition->from->format('Y-m');
        $this->to = $definition->to->format('Y-m');

        if ($this->reportId === '') {
            $this->reportRolling = $this->to === CarbonImmutable::now()->format('Y-m');
        }
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

        if ($property === 'to') {
            $this->reportRolling = $this->to === CarbonImmutable::now()->format('Y-m');
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

    /** Opens a saved report: its choices run again with your own permissions and scope. */
    public function openReport(int $id): void
    {
        $report = $this->openable($id);

        $this->redirectRoute('analysis.index', ['rapport' => $report->id], navigate: true);
    }

    public function saveReport(SaveReport $save): void
    {
        $this->persist($save, null);
    }

    public function updateReport(SaveReport $save): void
    {
        $this->persist($save, $this->openable((int) $this->reportId));
    }

    public function deleteReport(int $id, DeleteReport $delete): void
    {
        $delete($this->user(), $this->company(), $this->openable($id));

        if ($this->reportId === (string) $id) {
            $this->reportId = '';
        }

        $this->toast(__('Rapporten är raderad.'));
    }

    public function pinReport(int $id, bool $pinned, PinReport $pin): void
    {
        $pin($this->user(), $this->company(), $this->openable($id), $pinned);

        $this->toast($pinned ? __('Rapporten visas nu på översikten.') : __('Rapporten visas inte längre på översikten.'));
    }

    public function export(string $format): ?StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 404);

        $user = $this->user();
        $company = $this->company();
        $datasets = DatasetCatalogue::for($user, $company);

        if ($datasets === []) {
            abort(403);
        }

        $dataset = $datasets[$this->source] ?? array_values($datasets)[0];
        $definition = AnalysisDefinition::normalise($dataset, $this->input());
        $context = new AnalysisContext($company, $user, DimensionScope::for($user, $company), $definition->from, $definition->periodEnd(), $definition->compare);
        $result = app(AnalysisRunner::class)->run($dataset, $definition, $context);

        if ($result->tooLarge || $result->rows === []) {
            $this->toast(__('Det finns inget att hämta för valet.'), 'error');

            return null;
        }

        $active = $this->reportId === '' ? null : $this->openableOrNull((int) $this->reportId);
        $table = ReportTable::from($active instanceof ReportDefinition ? $active->name : $dataset->label(), $dataset, $definition, $result);

        if ($format === 'pdf') {
            if (ReportFiles::tooLargeForPdf($table)) {
                $this->toast(__('Tabellen är för stor för en PDF. Hämta den som CSV eller Excel, eller välj färre rader och kolumner.'), 'error');

                return null;
            }

            if (! WorkQuota::attempt($company, 'pdf')) {
                $this->toast(WorkQuota::message(), 'error');

                return null;
            }
        }

        Audit::record('analysis.exported', null, ['dataset' => $dataset->key(), 'format' => $format, 'rows' => count($table->rows), 'report_id' => $active?->id], $company, $user);

        [$contents, $type] = match ($format) {
            'csv' => [ReportFiles::csv($table), 'text/csv; charset=UTF-8'],
            'xlsx' => [ReportFiles::xlsx($table), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            default => [ReportFiles::pdf($company, $table), 'application/pdf'],
        };

        $this->toast(__('Filen laddas ner.'));

        $name = Str::slug($table->title) !== '' ? Str::slug($table->title) : 'analys';

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $name.'-'.now()->format('Ymd').'.'.$format, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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

        $reports = SavedReports::openableBy($user, $company);
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
            'reports' => $reports,
            'activeReport' => $this->reportId === '' ? null : $reports->firstWhere('id', (int) $this->reportId),
            'canShare' => $canShare = $user->hasTeamPermission($company, 'analysis:share'),
            'listCut' => $reports->count() >= SavedReports::LIST_LIMIT,
            'userId' => $user->id,
            'canUpdateActive' => ($active = $this->reportId === '' ? null : $reports->firstWhere('id', (int) $this->reportId)) !== null && ($active->owner_user_id === $user->id || ($active->owner_user_id === null && $canShare)),
        ]);
    }

    /** @param  array<string, Dataset>  $datasets */
    private function loadReport(array $datasets): void
    {
        $report = $this->openableOrNull((int) $this->reportId);

        if (! $report instanceof ReportDefinition || ! isset($datasets[$report->dataset])) {
            abort(404);
        }

        $definition = AnalysisDefinition::normalise($datasets[$report->dataset], SavedReports::input($report));
        $this->source = $report->dataset;
        $this->column = $definition->column ?? '';
        $this->compare = $definition->compare;
        $this->sort = $definition->sort;
        $this->rows = $definition->rows;
        $this->metric = $definition->measure;
        $this->from = $definition->from->format('Y-m');
        $this->to = $definition->to->format('Y-m');
        $this->reportName = $report->name;
        $this->reportVisibility = $report->visibility === ReportVisibility::Company && ! $this->user()->hasTeamPermission($this->company(), 'analysis:share') ? ReportVisibility::Private->value : $report->visibility->value;
        $this->reportRolling = ($report->definition['rolling'] ?? false) === true;
        $this->filterDimension1 = $this->filterDimension2 = '';
        $this->filterValues1 = $this->filterValues2 = [];

        $slot = 1;

        foreach ($definition->filters as $key => $values) {
            if ($slot === 1) {
                $this->filterDimension1 = $key;
                $this->filterValues1 = $values;
            } else {
                $this->filterDimension2 = $key;
                $this->filterValues2 = $values;
            }

            $slot++;
        }
    }

    private function persist(SaveReport $save, ?ReportDefinition $existing): void
    {
        $user = $this->user();
        $company = $this->company();
        $datasets = DatasetCatalogue::for($user, $company);
        $dataset = $datasets[$this->source] ?? array_values($datasets)[0] ?? null;

        abort_if($dataset === null, 403);

        try {
            $report = $save($user, $company, [
                ...$this->input(),
                'dataset' => $dataset->key(),
                'name' => $this->reportName,
                'visibility' => $this->visibilityToSave($user, $company),
                'rolling' => $this->reportRolling,
            ], $existing);
        } catch (ValidationException $exception) {
            $this->addError('reportName', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->resetErrorBag('reportName');
        $this->reportId = (string) $report->id;
        $this->toast($existing instanceof ReportDefinition ? __('Rapporten :name är uppdaterad.', ['name' => $report->name]) : __('Rapporten :name är sparad.', ['name' => $report->name]));
    }

    /** What the person may save as: a company report only with analysis:share, else private. */
    private function visibilityToSave(User $user, Company $company): string
    {
        $chosen = ReportVisibility::tryFrom($this->reportVisibility) ?? ReportVisibility::Private;

        return ($chosen === ReportVisibility::Company && ! $user->hasTeamPermission($company, 'analysis:share') ? ReportVisibility::Private : $chosen)->value;
    }

    private function openable(int $id): ReportDefinition
    {
        return $this->openableOrNull($id) ?? abort(404);
    }

    /** A report of this company that the person may open, else null: another company's id and another person's private report look the same. */
    private function openableOrNull(int $id): ?ReportDefinition
    {
        $report = ReportDefinition::query()->where('company_id', $this->company()->id)->find($id);

        return $report instanceof ReportDefinition && $this->user()->can('view', $report) ? $report : null;
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

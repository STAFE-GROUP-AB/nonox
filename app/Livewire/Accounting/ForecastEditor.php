<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Budget\ForecastInput;
use App\Actions\Budget\ManageBudgetVersion;
use App\Actions\Budget\SaveForecast;
use App\Domain\Budget\BudgetActuals;
use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\BudgetMonths;
use App\Domain\Budget\BudgetScope;
use App\Domain\Budget\ForecastEngine;
use App\Domain\Budget\ForecastMethod;
use App\Domain\Budget\ForecastSettings;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The grid of a rolling forecast (ADR-081 gap 2 scope 5): result accounts ×
 * the months of the fiscal year. Months up to the cut-off are the outcome and
 * cannot be typed in; every month after it shows what the row's method gives,
 * and a person may type over it (an override, marked, kept when the forecast
 * is computed again; an emptied cell goes back to the method). "Spara och
 * räkna om" stores the choices and computes the forecast again. A fixed
 * number of queries per render, whatever the size of the grid.
 */
class ForecastEditor extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $versionId;

    /** @var list<string> */
    #[Locked]
    public array $months = [];

    /** @var list<array{key: string, account_id: int, cost_center_id: ?int, project_id: ?int, revenue: bool}> */
    #[Locked]
    public array $rows = [];

    /** @var array<string, array<int, string>> what the method gives (or the outcome), in kronor as shown, per row key and month index */
    #[Locked]
    public array $computed = [];

    /** @var list<string> rows added on this page, not yet saved */
    #[Locked]
    public array $extraRows = [];

    /** @var array<string, array<int, mixed>> months typed by hand, kronor as typed; mixed, since the browser sends it */
    public array $cells = [];

    /** @var array<string, mixed> a row's own method, '' for the default; mixed, since the browser sends it */
    public array $rowMethods = [];

    public string $cutoff = '';

    public string $revenueMethod = '';

    public string $costMethod = '';

    public string $averageMonths = '';

    public string $growth = '';

    public string $newAccount = '';

    public string $newCostCenter = '';

    public string $newProject = '';

    public function mount(BudgetVersion $version): void
    {
        abort_unless($this->user()->can('view', $version), 403);
        abort_unless($version->kind === BudgetKind::Forecast, 404);

        $version->load('fiscalYear');
        $this->versionId = $version->id;
        $this->months = BudgetMonths::of($version->fiscalYear);
        $this->load($version);
    }

    public function addRow(): void
    {
        $this->authorizeChange();
        $this->resetErrorBag();

        $account = Account::query()->where('number', (int) $this->newAccount)->first();

        if (! $account instanceof Account || ! BudgetMonths::isBudgetable($account->number)) {
            $this->addError('newAccount', __('Välj ett resultatkonto (3000–8998).'));

            return;
        }

        $costCenter = $this->newCostCenter === '' ? null : CostCenter::query()->where('code', $this->newCostCenter)->value('id');
        $project = $this->newProject === '' ? null : Project::query()->where('code', $this->newProject)->value('id');
        $key = ForecastEngine::key($account->id, $costCenter === null ? null : (int) $costCenter, $project === null ? null : (int) $project);

        if (isset($this->computed[$key])) {
            $this->addError('newAccount', __('Raden finns redan i prognosen.'));

            return;
        }

        $this->rows[] = ['key' => $key, 'account_id' => $account->id, 'cost_center_id' => $costCenter === null ? null : (int) $costCenter, 'project_id' => $project === null ? null : (int) $project, 'revenue' => BudgetMonths::isRevenue($account->number)];
        $this->computed[$key] = array_fill(0, count($this->months), '');
        $this->cells[$key] = array_fill(0, count($this->months), '');
        $this->rowMethods[$key] = '';
        $this->extraRows[] = $key;
        $this->reset('newAccount', 'newCostCenter', 'newProject');

        $this->toast(__('Raden för konto :account är tillagd. Spara och räkna om, så får den sin metod.', ['account' => $account->number]));
    }

    /** Every month typed by hand on the row goes back to the row's method (when saved). */
    public function resetRow(string $key): void
    {
        $this->authorizeChange();

        if (! isset($this->computed[$key])) {
            return;
        }

        $this->cells[$key] = array_fill(0, count($this->months), '');

        $this->toast(__('Raden följer sin metod igen. Spara och räkna om för att behålla det.'));
    }

    public function save(SaveForecast $save): void
    {
        $this->authorizeChange();
        $this->resetErrorBag();

        $version = $this->version();
        $cutoff = $this->cutoff !== '' ? $this->cutoff : (string) $version->forecast_cutoff?->format('Y-m');
        $numbers = Account::query()->whereIn('id', array_column($this->rows, 'account_id'))->pluck('number', 'id');
        $overrides = [];
        $invalid = false;

        foreach ($this->rows as $row) {
            foreach ($this->months as $index => $month) {
                $raw = $this->cells[$row['key']][$index] ?? '';
                $typed = is_string($raw) ? trim($raw) : null;

                // Only months after the cut-off can be typed; with a later cut-off the outcome takes over.
                if ($typed === '' || $month <= $cutoff) {
                    continue;
                }

                $ore = $typed === null ? null : BudgetMonths::parseCell($typed);

                if ($ore === null) {
                    $this->addError("cells.{$row['key']}.{$index}", __('Inget giltigt belopp'));
                    $invalid = true;

                    continue;
                }

                $overrides[$row['key']][$month] = BudgetMonths::shown((int) ($numbers[$row['account_id']] ?? 0), $ore);
            }
        }

        if ($invalid) {
            $this->toast(__('Några rutor innehåller inget giltigt belopp (högst 100 miljarder kr per ruta). Rätta de markerade rutorna och spara igen.'), 'error');

            return;
        }

        $saved = $save($this->user(), $version, [
            'cutoff' => $cutoff,
            'revenue_method' => $this->revenueMethod,
            'cost_method' => $this->costMethod,
            'average_months' => $this->averageMonths,
            'growth' => $this->growth,
            'row_methods' => array_filter($this->rowMethods, fn (mixed $method): bool => is_string($method) && $method !== ''),
            'overrides' => $overrides,
            // Every row on the page, so a row with its own method stays when the method gives nothing.
            'extra_rows' => array_values(array_unique([...array_column($this->rows, 'key'), ...$this->extraRows])),
        ]);

        $this->extraRows = [];
        $this->load($saved->refresh());
        $this->dispatch('forecast-saved');
        $this->toast(__('Prognosen är sparad och omräknad.'));
    }

    public function lock(ManageBudgetVersion $manage): void
    {
        $version = $manage->lock($this->user(), $this->version());

        $this->toast(__(':name är låst. Gör en ny prognos från den om du vill ändra något.', ['name' => $version->name]));
    }

    public function activate(ManageBudgetVersion $manage): void
    {
        $version = $manage->activate($this->user(), $this->version());

        $this->toast(__(':name är nu den prognos som Rullande 12 månader läser.', ['name' => $version->name]));
    }

    public function render(): View
    {
        $version = $this->version();
        $hasBudget = BudgetVersion::query()->where('fiscal_year_id', $version->fiscal_year_id)->where('kind', BudgetKind::Budget->value)->where('is_active', true)->exists();
        $previous = BudgetActuals::previousYear($version->fiscalYear);
        $lockedUntil = Company::query()->whereKey($version->company_id)->value('locked_until_date');
        $lockedUntil = $lockedUntil === null ? null : CarbonImmutable::parse((string) $lockedUntil);
        $cutoff = $this->cutoff !== '' ? $this->cutoff : (string) $version->forecast_cutoff?->format('Y-m');
        $averageMonths = filter_var($this->averageMonths, FILTER_VALIDATE_INT);

        return view('livewire.accounting.forecast-editor', [
            'version' => $version,
            'storedCutoff' => (string) $version->forecast_cutoff?->format('Y-m'),
            'cutoffs' => ForecastInput::cutoffs($version->fiscalYear),
            'methods' => ForecastMethod::cases(),
            'hasBudget' => $hasBudget,
            'hasPreviousYear' => $previous !== null,
            'averageWindow' => count(ForecastEngine::window($version->fiscalYear, $previous, $cutoff, is_int($averageMonths) ? max(1, min(ForecastSettings::MAX_AVERAGE_MONTHS, $averageMonths)) : ForecastSettings::DEFAULT_AVERAGE_MONTHS)),
            'cutoffUnlocked' => $cutoff !== '' && ForecastInput::unlocked($cutoff, $lockedUntil),
            'accounts' => Account::query()->whereBetween('number', [BudgetMonths::FIRST_ACCOUNT, BudgetMonths::LAST_ACCOUNT])->orderBy('number')->get(['id', 'number', 'name', 'is_active'])->keyBy('id'),
            'costCenters' => CostCenter::query()->orderBy('code')->get(['id', 'code', 'name', 'is_active'])->keyBy('id'),
            'projects' => Project::query()->orderBy('code')->get(['id', 'code', 'name', 'is_active'])->keyBy('id'),
            'canChange' => $this->user()->can('update', $version),
            'canManage' => $this->user()->can('manage', $version),
        ]);
    }

    private function load(BudgetVersion $version): void
    {
        $settings = ForecastSettings::fromArray($version->method);
        $this->cutoff = (string) $version->forecast_cutoff?->format('Y-m');
        $this->revenueMethod = $settings->revenue->value;
        $this->costMethod = $settings->costs->value;
        $this->averageMonths = (string) $settings->averageMonths;
        $this->growth = $settings->growthBasisPoints === 0 ? '' : str_replace('.', ',', rtrim(rtrim(number_format($settings->growthBasisPoints / 100, 2, '.', ''), '0'), '.'));
        $this->rows = [];
        $this->computed = [];
        $this->cells = [];
        $this->rowMethods = [];
        $index = array_flip($this->months);

        $lines = BudgetScope::lines($version->id)
            ->join('accounts', 'accounts.id', '=', 'budget_lines.account_id')
            ->orderBy('accounts.number')->orderByRaw('COALESCE(budget_lines.cost_center_id, 0)')->orderByRaw('COALESCE(budget_lines.project_id, 0)')
            ->select('budget_lines.account_id', 'budget_lines.cost_center_id', 'budget_lines.project_id', 'budget_lines.period', 'budget_lines.amount_ore', 'budget_lines.is_override', 'accounts.number')
            ->toBase()
            ->get();

        foreach ($lines as $line) {
            $key = ForecastEngine::key((int) $line->account_id, $line->cost_center_id === null ? null : (int) $line->cost_center_id, $line->project_id === null ? null : (int) $line->project_id);

            if (! isset($this->computed[$key])) {
                $this->rows[] = ['key' => $key, 'account_id' => (int) $line->account_id, 'cost_center_id' => $line->cost_center_id === null ? null : (int) $line->cost_center_id, 'project_id' => $line->project_id === null ? null : (int) $line->project_id, 'revenue' => BudgetMonths::isRevenue((int) $line->number)];
                $this->computed[$key] = array_fill(0, count($this->months), '');
                $this->cells[$key] = array_fill(0, count($this->months), '');
                $this->rowMethods[$key] = isset($settings->rowMethods[$key]) ? $settings->rowMethods[$key]->value : '';
            }

            $month = substr((string) $line->period, 0, 7);

            if (! isset($index[$month])) {
                continue;
            }

            $shown = self::kronor(BudgetMonths::shown((int) $line->number, (int) $line->amount_ore));

            if ((bool) $line->is_override) {
                $this->cells[$key][$index[$month]] = $shown === '' ? '0' : $shown;
            } else {
                $this->computed[$key][$index[$month]] = $shown;
            }
        }

        // A row with its own method but nothing to show (yet) stays on the page.
        $missing = array_diff_key($settings->rowMethods, $this->computed);
        $numbers = $missing === [] ? collect() : Account::query()->whereIn('id', array_map(fn (string $key): int => (int) explode('-', $key)[0], array_keys($missing)))->pluck('number', 'id');

        foreach ($missing as $key => $method) {
            [$accountId, $costCenterId, $projectId] = array_map(intval(...), explode('-', (string) $key));

            if (! isset($numbers[$accountId])) {
                continue;
            }

            $this->rows[] = ['key' => (string) $key, 'account_id' => $accountId, 'cost_center_id' => $costCenterId === 0 ? null : $costCenterId, 'project_id' => $projectId === 0 ? null : $projectId, 'revenue' => BudgetMonths::isRevenue((int) $numbers[$accountId])];
            $this->computed[(string) $key] = array_fill(0, count($this->months), '');
            $this->cells[(string) $key] = array_fill(0, count($this->months), '');
            $this->rowMethods[(string) $key] = $method->value;
        }
    }

    private function authorizeChange(): void
    {
        abort_unless($this->user()->can('update', $this->version()), 403);
    }

    private function version(): BudgetVersion
    {
        return BudgetVersion::query()->with('fiscalYear')->findOrFail($this->versionId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** Kronor as typed in the grid: no thousands separator, decimal comma, empty for zero. */
    private static function kronor(int $ore): string
    {
        if ($ore === 0) {
            return '';
        }

        $sign = $ore < 0 ? '-' : '';
        $absolute = abs($ore);

        return $absolute % 100 === 0
            ? $sign.intdiv($absolute, 100)
            : $sign.intdiv($absolute, 100).','.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}

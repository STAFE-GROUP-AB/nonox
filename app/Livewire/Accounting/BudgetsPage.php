<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\CreateForecast;
use App\Actions\Budget\ForecastInput;
use App\Actions\Budget\ManageBudgetVersion;
use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\ForecastEngine;
use App\Domain\Budget\ForecastMethod;
use App\Domain\Budget\ForecastSettings;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The budget versions of every fiscal year, and a new version: empty, a copy
 * of another version or last year's outcome, optionally changed by a
 * percentage (ADR-081 gap 2); and a new rolling forecast from a cut-off
 * month (E5). Three queries, whatever the number of versions.
 */
class BudgetsPage extends Component
{
    use ShowsToasts;

    public bool $creating = false;

    /** @var array{fiscal_year_id: string, name: string, start: string, source_version_id: string, percent: string} */
    public array $form = ['fiscal_year_id' => '', 'name' => '', 'start' => CreateBudgetVersion::EMPTY, 'source_version_id' => '', 'percent' => ''];

    public bool $creatingForecast = false;

    /** @var array{fiscal_year_id: string, name: string, cutoff: string, revenue_method: string, cost_method: string, average_months: string, growth: string, source_version_id: string} */
    public array $forecastForm = ['fiscal_year_id' => '', 'name' => '', 'cutoff' => '', 'revenue_method' => 'BUDGET', 'cost_method' => 'AVERAGE', 'average_months' => '3', 'growth' => '', 'source_version_id' => ''];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [BudgetVersion::class, $this->company()]), 403);
    }

    public function create(): void
    {
        abort_unless($this->user()->can('create', [BudgetVersion::class, $this->company()]), 403);

        $year = FiscalYear::query()->whereDate('start_date', '>', now()->toDateString())->orderBy('start_date')->first()
            ?? FiscalYear::query()->whereDate('start_date', '<=', now()->toDateString())->orderByDesc('start_date')->first();

        $this->form = [
            'fiscal_year_id' => (string) $year?->id,
            'name' => $year === null ? __('Budget') : __('Budget :year', ['year' => $year->start_date->format('Y')]),
            'start' => CreateBudgetVersion::EMPTY,
            'source_version_id' => '',
            'percent' => '',
        ];
        $this->creating = true;
        $this->creatingForecast = false;
        $this->resetErrorBag();
    }

    /** A new forecast: the current fiscal year, the last ended month, owner decision 7's methods. */
    public function createForecast(): void
    {
        abort_unless($this->user()->can('create', [BudgetVersion::class, $this->company()]), 403);

        $year = FiscalYear::query()->whereDate('start_date', '<=', now()->toDateString())->orderByDesc('start_date')->first()
            ?? FiscalYear::query()->orderBy('start_date')->first();

        $this->forecastForm = ['fiscal_year_id' => (string) $year?->id, 'name' => '', 'cutoff' => '', 'revenue_method' => '', 'cost_method' => ForecastMethod::Average->value, 'average_months' => (string) ForecastSettings::DEFAULT_AVERAGE_MONTHS, 'growth' => '', 'source_version_id' => ''];
        $this->forecastYearChosen($year);
        $this->creatingForecast = true;
        $this->creating = false;
        $this->resetErrorBag();
    }

    public function updatedForecastFormFiscalYearId(): void
    {
        $this->forecastYearChosen(FiscalYear::query()->find((int) $this->forecastForm['fiscal_year_id']));
    }

    public function cancel(): void
    {
        $this->creating = false;
        $this->creatingForecast = false;
        $this->resetErrorBag();
    }

    public function saveForecast(CreateForecast $create): void
    {
        $version = $create($this->user(), $this->company(), [
            'fiscal_year_id' => $this->forecastForm['fiscal_year_id'] === '' ? null : (int) $this->forecastForm['fiscal_year_id'],
            'name' => $this->forecastForm['name'],
            'cutoff' => $this->forecastForm['cutoff'],
            'revenue_method' => $this->forecastForm['revenue_method'],
            'cost_method' => $this->forecastForm['cost_method'],
            'average_months' => $this->forecastForm['average_months'],
            'growth' => $this->forecastForm['growth'],
            'source_version_id' => $this->forecastForm['source_version_id'] === '' ? null : (int) $this->forecastForm['source_version_id'],
        ]);

        Toast::flash(__('Prognosen :name är skapad.', ['name' => $version->name]));

        $this->redirectRoute('accounting.budgets.show', ['budgetVersion' => $version->id]);
    }

    public function save(CreateBudgetVersion $create): void
    {
        $version = $create($this->user(), $this->company(), [
            'fiscal_year_id' => $this->form['fiscal_year_id'] === '' ? null : (int) $this->form['fiscal_year_id'],
            'name' => $this->form['name'],
            'start' => $this->form['start'],
            'source_version_id' => $this->form['source_version_id'] === '' ? null : (int) $this->form['source_version_id'],
            'percent' => $this->form['percent'],
        ]);

        Toast::flash(__('Budgeten :name är skapad.', ['name' => $version->name]));

        $this->redirectRoute('accounting.budgets.show', ['budgetVersion' => $version->id]);
    }

    public function activate(int $id, ManageBudgetVersion $manage): void
    {
        $version = $manage->activate($this->user(), $this->find($id));

        $this->toast($version->kind === BudgetKind::Forecast
            ? __(':name är nu den prognos som Rullande 12 månader läser.', ['name' => $version->name])
            : __(':name är nu den budget som rapporterna jämför med.', ['name' => $version->name]));
    }

    public function delete(int $id, ManageBudgetVersion $manage): void
    {
        $version = $this->find($id);

        try {
            $manage->delete($this->user(), $version);
        } catch (ValidationException $refused) {
            $this->toast(collect($refused->errors())->flatten()->implode(' '), 'error');

            return;
        }

        $this->toast($version->kind === BudgetKind::Forecast
            ? __('Prognosen :name är borttagen.', ['name' => $version->name])
            : __('Budgeten :name är borttagen.', ['name' => $version->name]));
    }

    public function render(): View
    {
        $fiscalYears = FiscalYear::query()->orderByDesc('start_date')->get();
        $versions = BudgetVersion::query()->with('fiscalYear')->withCount('lines')->orderByDesc('fiscal_year_id')->orderBy('kind')->orderBy('name')->get();
        $forecastYear = $fiscalYears->firstWhere('id', (int) $this->forecastForm['fiscal_year_id']);
        $previousYear = $forecastYear instanceof FiscalYear ? $fiscalYears->first(fn (FiscalYear $year): bool => $year->end_date->toDateString() === $forecastYear->start_date->subDay()->toDateString()) : null;
        $cutoff = $this->forecastForm['cutoff'];
        $averageMonths = filter_var($this->forecastForm['average_months'], FILTER_VALIDATE_INT);

        return view('livewire.accounting.budgets', [
            'fiscalYears' => $fiscalYears,
            'versions' => $versions,
            'canManage' => $this->user()->can('create', [BudgetVersion::class, $this->company()]),
            'cutoffs' => $forecastYear instanceof FiscalYear ? ForecastInput::cutoffs($forecastYear) : [],
            'forecastHasBudget' => $versions->contains(fn (BudgetVersion $version): bool => $version->fiscal_year_id === $forecastYear?->id && $version->kind === BudgetKind::Budget && $version->is_active),
            'forecasts' => $versions->where('kind', BudgetKind::Forecast)->values(),
            'methods' => ForecastMethod::cases(),
            'forecastHasPreviousYear' => $previousYear !== null,
            'forecastAverageWindow' => $forecastYear instanceof FiscalYear && $cutoff !== ''
                ? count(ForecastEngine::window($forecastYear, $previousYear, $cutoff, is_int($averageMonths) ? max(1, min(ForecastSettings::MAX_AVERAGE_MONTHS, $averageMonths)) : ForecastSettings::DEFAULT_AVERAGE_MONTHS))
                : 0,
            'forecastCutoffUnlocked' => $cutoff !== '' && ForecastInput::unlocked($cutoff, $this->company()->locked_until_date),
        ]);
    }

    /**
     * The cut-off and the revenue method follow the chosen year: the last
     * month of the locked period (else the last ended month), and the active budget when the year has one, else the
     * average (owner decision 7 needs a budget to read).
     */
    private function forecastYearChosen(?FiscalYear $year): void
    {
        $cutoff = $year === null ? null : ForecastInput::defaultCutoff($year, $this->company()->locked_until_date);
        $hasBudget = $year !== null && BudgetVersion::query()->where('fiscal_year_id', $year->id)->where('kind', BudgetKind::Budget->value)->where('is_active', true)->exists();

        $this->forecastForm['cutoff'] = (string) $cutoff;
        $this->forecastForm['revenue_method'] = $hasBudget ? ForecastMethod::Budget->value : ForecastMethod::Average->value;
        $this->forecastForm['name'] = $cutoff === null ? __('Prognos') : __('Prognos :month', ['month' => ForecastInput::monthName($cutoff)]);
    }

    private function find(int $id): BudgetVersion
    {
        return BudgetVersion::query()->findOrFail($id);
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

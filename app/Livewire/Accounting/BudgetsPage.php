<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\ManageBudgetVersion;
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
 * percentage (ADR-081 gap 2). Three queries, whatever the number of versions.
 */
class BudgetsPage extends Component
{
    use ShowsToasts;

    public bool $creating = false;

    /** @var array{fiscal_year_id: string, name: string, start: string, source_version_id: string, percent: string} */
    public array $form = ['fiscal_year_id' => '', 'name' => '', 'start' => CreateBudgetVersion::EMPTY, 'source_version_id' => '', 'percent' => ''];

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
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->creating = false;
        $this->resetErrorBag();
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

        $this->toast(__(':name är nu den budget som rapporterna jämför med.', ['name' => $version->name]));
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

        $this->toast(__('Budgeten :name är borttagen.', ['name' => $version->name]));
    }

    public function render(): View
    {
        return view('livewire.accounting.budgets', [
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'versions' => BudgetVersion::query()->with('fiscalYear')->withCount('lines')->orderByDesc('fiscal_year_id')->orderBy('kind')->orderBy('name')->get(),
            'canManage' => $this->user()->can('create', [BudgetVersion::class, $this->company()]),
        ]);
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

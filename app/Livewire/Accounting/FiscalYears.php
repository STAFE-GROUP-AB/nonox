<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accounting\CarryForwardBalances;
use App\Actions\Accounting\ChangeFirstFiscalYear;
use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\Accounting\CreateNextFiscalYear;
use App\Domain\Accounting\FiscalYearRules;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\ArchivePackage;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

class FiscalYears extends Component
{
    use ShowsToasts;

    public ?int $editingId = null;

    public string $startDate = '';

    public string $endDate = '';

    public function createNext(CreateNextFiscalYear $createNextFiscalYear): void
    {
        try {
            $fiscalYear = $createNextFiscalYear($this->user(), $this->company());
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('Räkenskapsåret :label är skapat.', ['label' => $fiscalYear->label()]));
    }

    public function carryForward(int $fiscalYearId, CarryForwardBalances $carryForward): void
    {
        $fiscalYear = FiscalYear::findOrFail($fiscalYearId);
        $next = $carryForward($this->user(), $fiscalYear);

        $this->toast(__('Utgående balanser för :from är ingående balanser för :to.', ['from' => $fiscalYear->label(), 'to' => $next->label()]));
    }

    public function createArchive(int $fiscalYearId, CreateArchivePackage $createArchivePackage): void
    {
        try {
            $package = $createArchivePackage($this->user(), FiscalYear::findOrFail($fiscalYearId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($package->status === ArchivePackage::DONE
            ? __('Arkivpaketet är klart att ladda ner.')
            : ($package->status === ArchivePackage::FAILED
                ? (string) $package->error
                : __('Arkivpaketet skapas. Det kan ta några minuter för ett stort år; sidan visar när det är klart.')), $package->status === ArchivePackage::FAILED ? 'error' : 'success');
    }

    public function startEditing(int $fiscalYearId): void
    {
        $fiscalYear = FiscalYear::findOrFail($fiscalYearId);

        $this->editingId = $fiscalYear->id;
        $this->startDate = $fiscalYear->start_date->toDateString();
        $this->endDate = $fiscalYear->end_date->toDateString();
        $this->resetErrorBag();
    }

    public function cancelEditing(): void
    {
        $this->reset('editingId', 'startDate', 'endDate');
    }

    public function save(ChangeFirstFiscalYear $changeFirstFiscalYear): void
    {
        $fiscalYear = FiscalYear::findOrFail($this->editingId);

        $changeFirstFiscalYear($this->user(), $fiscalYear, $this->startDate, $this->endDate);
        $fiscalYear->refresh();

        $this->reset('editingId', 'startDate', 'endDate');
        $this->toast(__('Räkenskapsåret är ändrat till :start – :end.', [
            'start' => $fiscalYear->start_date->toDateString(),
            'end' => $fiscalYear->end_date->toDateString(),
        ]));
    }

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('viewAny', [FiscalYear::class, $user->currentCompany()]), 403);
    }

    public function render(): View
    {
        $fiscalYears = FiscalYear::query()->orderByDesc('start_date')->get();
        $canArchive = $this->user()->hasTeamPermission($this->company(), 'export:archive');
        $calendarYear = FiscalYearRules::requiresCalendarYear($this->company()->legal_form);
        $latest = $fiscalYears->first();
        $packages = $canArchive
            ? ArchivePackage::query()->whereIn('id', ArchivePackage::query()->selectRaw('max(id)')->groupBy('fiscal_year_id'))->get()->keyBy('fiscal_year_id')
            : collect();

        return view('livewire.accounting.fiscal-years', [
            'fiscalYears' => $fiscalYears,
            'canManage' => $this->user()->can('create', [FiscalYear::class, $this->company()]),
            'canExport' => $this->user()->hasTeamPermission($this->company(), 'export:sie'),
            'canArchive' => $canArchive,
            'packages' => $packages,
            'building' => $packages->contains(fn (ArchivePackage $package): bool => $package->isBusy()),
            // The first year, while its dates may change: alone, or followed only by an empty year NONOX created by itself.
            'onlyYearId' => $fiscalYears->count() <= 2 && $fiscalYears->last() !== null && app(ChangeFirstFiscalYear::class)->changeable($fiscalYears->last())
                ? $fiscalYears->last()->id
                : null,
            'nextLabels' => $fiscalYears->mapWithKeys(function (FiscalYear $year) use ($calendarYear): array {
                [$start, $end] = FiscalYearRules::nextYear($year->end_date->addDay(), $calendarYear);

                return [$year->id => (new FiscalYear(['start_date' => $start, 'end_date' => $end]))->label()];
            })->all(),
            'calendarYearWarning' => $calendarYear && $latest !== null && ! FiscalYearRules::isCalendarAligned($latest->end_date)
                ? __('En enskild firma ska ha kalenderår som räkenskapsår, men det senaste räkenskapsåret slutar :end. Befintliga år ändras inte. Nästa räkenskapsår som skapas slutar den 31 december, så att företaget går över till kalenderår.', ['end' => $latest->end_date->toDateString()])
                : null,
            'today' => now()->toImmutable()->startOfDay(),
        ]);
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

<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Actions\Projects\ProposeProjectWipVouchers;
use App\Actions\Projects\SetFixedPriceMethod;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Projects\FixedPriceMethods;
use App\Domain\Projects\ProjectWip;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Domain\FixedPriceMethod;
use App\Modules\WorkOrders\Domain\OngoingWork;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokslut → Pågående projekt (ADR-081 gap 6 scope 4, E8): work in progress
 * on projects without a work order at the balance sheet date, with the
 * figures and a proposed adjustment. "Skapa verifikationsutkast" makes two
 * drafts, the adjustment and its reversal, from exactly the figures shown
 * (the fingerprint is locked to them); nothing is posted here.
 *
 * Not for a member limited to cost centres or projects.
 */
class ProjectWipPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'datum')]
    public string $date = '';

    public string $method = '';

    /** The figures the page showed, so a proposal is made only from them. */
    #[Locked]
    public string $fingerprint = '';

    public function mount(): void
    {
        Gate::authorize('proposeWip', [Project::class, $this->company()]);

        if ($this->date === '') {
            $year = FiscalYear::query()->whereDate('end_date', '<', CarbonImmutable::today()->addMonths(6)->toDateString())->orderByDesc('end_date')->first();
            $this->date = ($year->end_date ?? CarbonImmutable::today()->endOfYear())->toDateString();
        }
    }

    public function saveMethod(SetFixedPriceMethod $set): void
    {
        $method = FixedPriceMethod::tryFrom($this->method);

        if ($method === null) {
            $this->addError('method', __('Välj en metod.'));

            return;
        }

        $company = $this->company();
        $before = FixedPriceMethods::for($company);
        $changed = $set($this->user(), $company, $method);
        $this->toast(__('Metoden för fastprisuppdrag är sparad: :method.', ['method' => $method->label()]));

        // K2 6.15: one method for all fixed-price jobs, and a change needs special reasons. Advice only.
        if ($changed && $before !== FixedPriceMethod::NotSet && FiscalYear::query()->where('status', FiscalYearStatus::Closed->value)->exists()) {
            $this->toast(__('Metoden för fastprisuppdrag har ändrats efter ett stängt räkenskapsår. Ett byte kräver särskilda skäl och ska förklaras i årsredovisningen (K2 6.15). Stäm av med din redovisningskonsult.'), 'error');
        }
    }

    public function propose(ProposeProjectWipVouchers $propose): void
    {
        $date = $this->parsedDate();

        if ($date === null) {
            $this->addError('date', __('Skriv balansdagen som ÅÅÅÅ-MM-DD.'));

            return;
        }

        try {
            [$adjustment] = $propose($this->user(), $this->company(), $date, $this->fingerprint);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        Toast::flash(__('Två verifikationsutkast är skapade: justeringen och återföringen. Granska och bokför dem, eller ta bort dem.'));
        $this->redirectRoute('accounting.vouchers.edit', $adjustment->id);
    }

    public function render(): View
    {
        Gate::authorize('proposeWip', [Project::class, $this->company()]);

        $company = $this->company();
        $date = $this->parsedDate();
        $wip = $date === null ? null : ProjectWip::at($company, $date);
        $this->fingerprint = $wip['fingerprint'] ?? '';

        if ($wip !== null && $this->method === '') {
            $this->method = $wip['method']->value;
        }

        return view('livewire.projects.project-wip-page', [
            'wip' => $wip,
            'dateError' => $date === null ? __('Skriv balansdagen som ÅÅÅÅ-MM-DD.') : null,
            'methods' => FixedPriceMethod::cases(),
            'canSetMethod' => $this->user()->hasTeamPermission($company, 'fiscal-years:manage'),
            'workOrdersOn' => app(ModuleManager::class)->isEnabled(WorkOrdersModule::KEY, $company),
            'accounts' => [OngoingWork::UNBILLED, OngoingWork::BILLED_AHEAD, OngoingWork::WORK_IN_PROGRESS],
        ]);
    }

    /** The balance sheet date as typed; null when it is not a real date (never a 500). */
    private function parsedDate(): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->date);

        return $date instanceof CarbonImmutable && $date->toDateString() === $this->date ? $date : null;
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

<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Livewire;

use App\Domain\Accounting\FiscalYearStatus;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Actions\SaveLabourKind;
use App\Modules\WorkOrders\Domain\FixedPriceMethod;
use App\Modules\WorkOrders\Domain\LabourKindType;
use App\Modules\WorkOrders\Domain\LabourUnit;
use App\Modules\WorkOrders\Livewire\Concerns\WorkOrdersPage;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Modules\WorkOrders\Support\Input;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Modules\WorkOrders\WorkOrdersSettings;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Arbetsorder's settings, on Arbetsorder → Inställningar and under
 * Moduler: a project per work order (O2), the material mark-up, the
 * year-end method for fixed-price jobs, and the kinds of work (O7).
 */
class SettingsForm extends Component
{
    use WorkOrdersPage;

    public const string NAME = 'work-orders.settings-form';

    #[Locked]
    public int $companyId;

    public bool $autoProject = true;

    public string $markup = '';

    public string $fixedPriceMethod = 'NOT_SET';

    /** null: no kind form open; 0: a new kind; otherwise the kind being edited */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, WorkOrdersModule::MANAGE), 403);

        $this->companyId = $company->id;
        $settings = WorkOrdersSettings::for($company);
        $this->autoProject = (bool) $settings['auto_project'];
        $this->markup = rtrim(rtrim(number_format(((int) $settings['material_markup_bp']) / 100, 2, ',', ''), '0'), ',');
        $this->fixedPriceMethod = (string) $settings['fixed_price_method'];
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, WorkOrdersModule::MANAGE), 403);

        $before = WorkOrdersSettings::fixedPriceMethod($company);

        $saved = $this->attempt(function () use ($modules, $company): void {
            $markup = Input::percentBp($this->markup, 'markup') ?? 0;

            if (FixedPriceMethod::tryFrom($this->fixedPriceMethod) === null) {
                throw ValidationException::withMessages(['method' => __('Välj en metod.')]);
            }

            $modules->updateSettings(WorkOrdersModule::KEY, $company, $this->user(), [
                'auto_project' => $this->autoProject,
                'material_markup_bp' => $markup,
                'fixed_price_method' => $this->fixedPriceMethod,
            ]);
        }, __('Inställningarna för arbetsorder är sparade.'), '');

        // K2 6.15 (review P3-7): one method for all fixed-price jobs, and a change needs special reasons. Advice only.
        if ($saved && $this->methodChangedAfterClosedYear($before)) {
            $this->toast(__('Metoden för fastprisuppdrag har ändrats efter ett stängt räkenskapsår. Ett byte kräver särskilda skäl och ska förklaras i årsredovisningen (K2 6.15). Stäm av med din redovisningskonsult.'), 'error');
        }
    }

    private function methodChangedAfterClosedYear(FixedPriceMethod $before): bool
    {
        return $before !== FixedPriceMethod::NotSet
            && $before->value !== $this->fixedPriceMethod
            && FiscalYear::query()->where('status', FiscalYearStatus::Closed->value)->exists();
    }

    public function createKind(): void
    {
        $this->editing = 0;
        $this->form = ['code' => '', 'name' => '', 'type' => LabourKindType::Labour->value, 'unit' => LabourUnit::Hour->value, 'default_rate' => '', 'is_active' => true];
    }

    public function editKind(int $id): void
    {
        $kind = LabourKind::query()->findOrFail($id);
        $this->editing = $kind->id;
        $this->form = ['name' => $kind->name, 'unit' => $kind->unit->value, 'default_rate' => $kind->default_rate_ore->format(false), 'is_active' => $kind->is_active];
    }

    public function cancelKind(): void
    {
        $this->reset('editing', 'form');
        $this->resetErrorBag();
    }

    public function saveKind(SaveLabourKind $save): void
    {
        $kind = $this->editing ? LabourKind::query()->findOrFail($this->editing) : null;
        $input = $kind === null ? $this->form : array_intersect_key($this->form, array_flip(['name', 'unit', 'default_rate', 'is_active']));

        if ($this->attempt(fn () => $save($this->user(), $this->company(), $input, $kind), $kind === null ? __('Typen av arbete är tillagd.') : __('Typen av arbete är sparad.'))) {
            $this->cancelKind();
        }
    }

    public function render(): View
    {
        return view('work-orders::livewire.settings-form', [
            'methods' => FixedPriceMethod::cases(),
            'kinds' => LabourKind::query()->orderBy('position')->get(),
            'types' => LabourKindType::cases(),
            'units' => LabourUnit::cases(),
            'rate' => fn (Money $money): string => $money->format(),
        ]);
    }
}

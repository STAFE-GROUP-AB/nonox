<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\AgreementsSettings;
use App\Modules\Agreements\Domain\AgreementChecksStep;
use App\Modules\Agreements\Domain\MinimumWageCheck;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\Agreements\Support\AgreementVersions;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\SalaryChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lön → Avtal: the agreements the company is bound by (member or
 * hängavtal, from and to), and per employment its agreement, lönegrupp,
 * branschvana and how overtime and OB are compensated, with the minimum
 * wage that applies this month (ADR-033 §3, risk "fel avtal på
 * anställningen": every employee's agreement, group and minimum is shown).
 */
class AgreementsPage extends Component
{
    use ShowsToasts;

    /** @var array{agreement_key: string, variant: string, bound_from: string, bound_to: string} */
    public array $binding = ['agreement_key' => '', 'variant' => 'MEMBER', 'bound_from' => '', 'bound_to' => ''];

    #[Locked]
    public ?int $editingBinding = null;

    #[Locked]
    public ?int $editingEmployment = null;

    /** @var array{collective_agreement_id: string, agreement_group: string, industry_experience_from: string, overtime_compensation: string, ob_compensation: string} */
    public array $terms = ['collective_agreement_id' => '', 'agreement_group' => '', 'industry_experience_from' => '', 'overtime_compensation' => '', 'ob_compensation' => ''];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'agreements:view'), 403);
    }

    public function editBinding(?int $id = null): void
    {
        $this->resetErrorBag();
        $binding = $id === null ? null : CollectiveAgreement::query()->findOrFail($id);
        $this->editingBinding = $binding === null ? 0 : $binding->id;
        $this->binding = [
            'agreement_key' => (string) $binding?->agreement_key,
            'variant' => $binding === null ? AgreementRuleVersion::MEMBER : (string) $binding->variant,
            'bound_from' => (string) $binding?->bound_from?->toDateString(),
            'bound_to' => (string) $binding?->bound_to?->toDateString(),
        ];
    }

    public function saveBinding(AgreementSetup $setup): void
    {
        $existing = $this->editingBinding ? CollectiveAgreement::query()->findOrFail($this->editingBinding) : null;
        $saved = $setup->saveBinding($this->user(), $this->company(), $this->binding, $existing);
        $this->editingBinding = null;
        $this->toast(__('Avtalet :agreement är sparat för företaget.', ['agreement' => $saved->name]));
    }

    public function removeBinding(AgreementSetup $setup, int $id): void
    {
        $binding = CollectiveAgreement::query()->findOrFail($id);
        $setup->removeBinding($this->user(), $this->company(), $binding);
        $this->toast(__('Avtalet :agreement är borttaget från företaget.', ['agreement' => $binding->name]));
    }

    public function editEmployment(int $id): void
    {
        $this->resetErrorBag();
        $employment = Employment::query()->findOrFail($id);
        $this->editingEmployment = $employment->id;
        $this->terms = [
            'collective_agreement_id' => (string) $employment->collective_agreement_id,
            'agreement_group' => (string) $employment->agreement_group,
            'industry_experience_from' => (string) $employment->industry_experience_from?->toDateString(),
            'overtime_compensation' => (string) $employment->overtime_compensation,
            'ob_compensation' => (string) $employment->ob_compensation,
        ];
    }

    public function saveEmployment(AgreementSetup $setup): void
    {
        $employment = Employment::query()->with('employee')->findOrFail($this->editingEmployment);
        $setup->saveEmployment($this->user(), $this->company(), $employment, $this->terms);
        $this->editingEmployment = null;
        $this->toast(__('Avtalsuppgifterna för :name är sparade.', ['name' => trim("{$employment->employee?->first_name} {$employment->employee?->last_name}")]));
    }

    public function cancel(): void
    {
        $this->editingBinding = null;
        $this->editingEmployment = null;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $company = $this->company();
        $today = CarbonImmutable::today();
        $catalog = AgreementVersions::catalog();
        $checked = AgreementVersions::companyChecked();
        $bindings = CollectiveAgreement::query()->whereNotNull('agreement_key')->orderBy('name')->get()->keyBy('id');
        $versions = AgreementRuleVersion::query()->whereIn('agreement_key', $bindings->pluck('agreement_key')->unique()->values())->orderBy('valid_from')->get();
        $employments = Employment::query()
            ->with('employee')
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today->toDateString()))
            ->orderBy('employee_id')
            ->get();
        // The salary in force this month, in one query (as ContextBuilder::salaryAt does per employment).
        $salaries = SalaryChange::query()
            ->whereIn('employment_id', $employments->modelKeys())
            ->whereDate('valid_from', '<=', $today->startOfMonth()->toDateString())
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get()
            ->groupBy('employment_id')
            ->map(fn ($changes): ?int => $changes->last()?->monthly_salary_ore?->ore);
        $warnings = AgreementsSettings::bool(app(ModuleManager::class)->settings(AgreementsModule::KEY, $company), 'minimum_wage_warnings');
        $rows = [];

        foreach ($employments as $employment) {
            $binding = $employment->collective_agreement_id === null ? null : $bindings->get($employment->collective_agreement_id);
            $version = $binding === null ? null : $versions
                ->filter(fn (AgreementRuleVersion $each): bool => $each->agreement_key === $binding->agreement_key && $each->variant === $binding->variant && $each->covers($today->endOfMonth()))
                ->last();
            $check = null;

            if ($version !== null && $employment->employee !== null && $version->agreementRules()->minimumWages() !== []) {
                $check = MinimumWageCheck::check($version->agreementRules(), $employment->agreement_group, $employment->employee->birth_date, $today->endOfMonth()->startOfDay(), $employment->industry_experience_from, $employment->start_date, $employment->salary_form, $salaries->get($employment->id) ?? $employment->monthly_salary_ore?->ore, $employment->hourly_rate_ore?->ore);
            }

            $rows[] = [
                'employment' => $employment,
                'binding' => $binding,
                'version' => $version,
                'usable' => $version !== null && AgreementVersions::isUsable($version, $checked),
                'group' => $version?->agreementRules()->group((string) $employment->agreement_group),
                'check' => $check,
                'warning' => $check === null || $version === null || ! $warnings ? null : AgreementChecksStep::warning($check, $version->agreementRules()->shortName()),
            ];
        }

        $editingBinding = $this->editingEmployment === null ? null : $bindings->get((int) $this->terms['collective_agreement_id']);
        $editingVersion = $editingBinding === null ? null : $catalog->get("{$editingBinding->agreement_key}|{$editingBinding->variant}");

        return view('agreements::livewire.page', [
            'canManage' => $this->user()->hasTeamPermission($company, 'agreements:manage'),
            'agreements' => AgreementVersions::agreements(),
            'catalog' => $catalog,
            'bindings' => $bindings,
            'rows' => $rows,
            'groups' => $editingVersion?->agreementRules()->groups() ?? [],
            'today' => $today,
        ]);
    }

    private function company(): Company
    {
        /** @var Company */
        return $this->user()->currentCompany();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}

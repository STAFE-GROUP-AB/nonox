<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Company\UpdateCompanyDetails;
use App\Domain\Company\AccountingMethod;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatPeriod;
use App\Livewire\Concerns\LooksUpCompanies;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CompanyDetailsForm extends Component
{
    use LooksUpCompanies;
    use ShowsToasts;

    #[Locked]
    public int $companyId;

    /** @var array<string, mixed> */
    public array $state = [];

    public function mount(Company $company): void
    {
        $this->companyId = $company->id;

        $this->state = [
            'name' => $company->name,
            'address_line1' => $company->address_line1,
            'postal_code' => $company->postal_code,
            'city' => $company->city,
            'email' => $company->email,
            'phone' => $company->phone,
            'website' => $company->website,
            'bankgiro' => $company->bankgiro,
            'plusgiro' => $company->plusgiro,
            'vat_period' => $company->vat_period->value,
            'fiscal_year_start_month' => $company->fiscal_year_start_month,
            'accounting_method' => $company->accounting_method->value,
            'reporting_framework' => $company->reporting_framework->value,
            'f_skatt' => $company->f_skatt,
            'employer_registered' => $company->employer_registered,
            'turnover_over_40_msek' => $company->turnover_over_40_msek,
        ];
    }

    public function save(UpdateCompanyDetails $updateCompanyDetails): void
    {
        $this->resetErrorBag();

        $updateCompanyDetails($this->user(), $this->company(), $this->state);

        $company = $this->company()->refresh();
        $this->state['bankgiro'] = $company->bankgiro;
        $this->state['plusgiro'] = $company->plusgiro;

        $this->toast(__('Uppgifterna för :company är sparade.', ['company' => $company->name]));
    }

    /** Fetching only proposes values, but only someone who may change them is offered it. */
    public function lookUpCompany(): void
    {
        abort_unless($this->user()->can('update', $this->company()), 403);

        $this->runCompanyLookup(false);
    }

    protected function registryFields(): array
    {
        return ['name' => 'state.name', 'address_line1' => 'state.address_line1', 'postal_code' => 'state.postal_code', 'city' => 'state.city'];
    }

    protected function registryNumber(): string
    {
        return (string) $this->company()->org_number;
    }

    protected function registryQuotaCompany(): ?Company
    {
        return $this->company();
    }

    public function render(): View
    {
        $company = $this->company();

        return view('livewire.company.company-details-form', [
            'company' => $company,
            'canUpdate' => $this->user()->can('update', $company),
            'accountingMethods' => AccountingMethod::cases(),
            'vatPeriods' => VatPeriod::cases(),
            'frameworks' => ReportingFramework::cases(),
            'months' => array_combine(range(1, 12), [
                __('januari'), __('februari'), __('mars'), __('april'), __('maj'), __('juni'),
                __('juli'), __('augusti'), __('september'), __('oktober'), __('november'), __('december'),
            ]),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * Only companies the user belongs to can be loaded, so a tampered id
     * answers 404 (AGENTS.md §13.2).
     */
    private function company(): Company
    {
        $company = $this->user()->companies()->firstWhere('id', $this->companyId);

        abort_unless($company instanceof Company, 404);

        return $company;
    }
}

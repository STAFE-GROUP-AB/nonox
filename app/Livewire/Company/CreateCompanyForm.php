<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Company\CreateCompany;
use App\Domain\Company\AccountingMethod;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatPeriod;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class CreateCompanyForm extends Component
{
    public string $name = '';

    public string $org_number = '';

    public string $legal_form = 'AB';

    public string $address_line1 = '';

    public string $postal_code = '';

    public string $city = '';

    public string $email = '';

    public int $fiscal_year_start_month = 1;

    public string $accounting_method = 'FAKTURAMETODEN';

    public bool $vat_registered = true;

    public string $vat_period = 'QUARTERLY';

    public string $reporting_framework = 'K2';

    public bool $f_skatt = true;

    public bool $employer_registered = false;

    public function create(CreateCompany $createCompany): void
    {
        /** @var User $user */
        $user = Auth::user();

        $this->resetErrorBag();

        $company = $createCompany->create($user, [
            'name' => $this->name,
            'org_number' => $this->org_number,
            'legal_form' => $this->legal_form,
            'address_line1' => $this->address_line1 ?: null,
            'postal_code' => $this->postal_code ?: null,
            'city' => $this->city ?: null,
            'email' => $this->email ?: null,
            'fiscal_year_start_month' => $this->fiscal_year_start_month,
            'accounting_method' => $this->accounting_method,
            'vat_registered' => $this->vat_registered,
            'vat_period' => $this->vat_registered ? $this->vat_period : VatPeriod::None->value,
            'reporting_framework' => $this->reporting_framework,
            'f_skatt' => $this->f_skatt,
            'employer_registered' => $this->employer_registered,
        ]);

        Toast::flash(__(':company är skapat. Du arbetar nu i det företaget.', ['company' => $company->name]));

        $this->redirectRoute('dashboard');
    }

    public function render(): View
    {
        return view('livewire.company.create-company-form', [
            'legalForms' => LegalForm::cases(),
            'accountingMethods' => AccountingMethod::cases(),
            'vatPeriods' => array_filter(VatPeriod::cases(), fn (VatPeriod $period) => $period !== VatPeriod::None),
            'frameworks' => ReportingFramework::cases(),
            'months' => array_combine(range(1, 12), [
                __('Januari'), __('Februari'), __('Mars'), __('April'), __('Maj'), __('Juni'),
                __('Juli'), __('Augusti'), __('September'), __('Oktober'), __('November'), __('December'),
            ]),
        ]);
    }
}

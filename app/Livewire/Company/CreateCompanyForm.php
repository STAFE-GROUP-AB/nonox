<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Company\CreateCompany;
use App\Domain\Accounting\FiscalYearRules;
use App\Domain\Company\AccountingMethod;
use App\Domain\Company\Industry;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\ReportingFrameworkRules;
use App\Domain\Company\VatPeriod;
use App\Livewire\Concerns\LooksUpCompanies;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\SpansCompanies;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class CreateCompanyForm extends Component implements SpansCompanies
{
    use LooksUpCompanies;

    public string $name = '';

    public string $org_number = '';

    public string $legal_form = 'AB';

    public string $address_line1 = '';

    public string $postal_code = '';

    public string $city = '';

    public string $email = '';

    public string $industry = '';

    public string $started_on = '';

    public int $fiscal_year_start_month = 1;

    public string $accounting_method = 'FAKTURAMETODEN';

    public bool $vat_registered = true;

    public string $vat_period = 'QUARTERLY';

    public string $reporting_framework = 'K2';

    public bool $f_skatt = true;

    public bool $employer_registered = false;

    public bool $accept_agreement = false;

    public bool $framework_confirmed = false;

    public bool $calendar_year_exempt = false;

    /** A new framework means new conditions to confirm. */
    public function updatedReportingFramework(): void
    {
        $this->framework_confirmed = false;
    }

    /** A sole trader always starts the year in January (BFL 3 kap. 1 §). */
    public function updatedLegalForm(): void
    {
        $this->framework_confirmed = false;

        if (FiscalYearRules::requiresCalendarYear(LegalForm::from($this->legal_form))) {
            $this->fiscal_year_start_month = 1;
        }
    }

    /** Name and organisation number from a SIE file uploaded in the wrong company, if any. */
    public function mount(): void
    {
        $prefill = (array) session()->pull('company.prefill', []);
        $this->name = (string) ($prefill['name'] ?? $this->name);
        $this->org_number = (string) ($prefill['org_number'] ?? $this->org_number);
    }

    public function updatedOrgNumber(): void
    {
        $this->lookUpCompanyAutomatically();
    }

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
            'accept_agreement' => $this->accept_agreement,
            'framework_confirmed' => $this->framework_confirmed,
            'calendar_year_exempt' => $this->calendar_year_exempt,
            'employer_registered' => $this->employer_registered,
            'industry' => $this->industry ?: null,
            'started_on' => $this->started_on ?: null,
        ]);

        Toast::flash(__(':company är skapat. Du arbetar nu i det företaget.', ['company' => $company->name]));

        $this->redirectRoute('dashboard');
    }

    protected function registryFields(): array
    {
        return ['name' => 'name', 'legal_form' => 'legal_form', 'address_line1' => 'address_line1', 'postal_code' => 'postal_code', 'city' => 'city'];
    }

    protected function registryNumber(): string
    {
        return $this->org_number;
    }

    /** The company does not exist yet: the lookup counts against the user's own limit. */
    protected function registryQuotaCompany(): ?Company
    {
        return null;
    }

    /**
     * The legal form select starts on AB, which is not a choice the user has made.
     *
     * @return array<string, string>
     */
    protected function registryPresets(): array
    {
        return ['legal_form' => 'AB'];
    }

    public function render(): View
    {
        $legalForm = LegalForm::tryFrom($this->legal_form) ?? LegalForm::Ab;
        $framework = ReportingFramework::tryFrom($this->reporting_framework) ?? ReportingFramework::K2;

        return view('livewire.company.create-company-form', [
            'frameworkRefusal' => ReportingFrameworkRules::refusal($legalForm, $framework),
            'frameworkConditions' => ReportingFrameworkRules::conditions($legalForm, $framework),
            'calendarYearOnly' => FiscalYearRules::requiresCalendarYear($legalForm),
            'askPartners' => FiscalYearRules::requiresCalendarYearWithNaturalPartner($legalForm) && $this->fiscal_year_start_month !== 1,
            'legalForms' => LegalForm::cases(),
            'industries' => Industry::cases(),
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

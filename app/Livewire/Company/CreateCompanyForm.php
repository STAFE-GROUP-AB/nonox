<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Company\CreateCompany;
use App\Domain\Accounting\FiscalYearRules;
use App\Domain\Company\AccountingMethod;
use App\Domain\Company\Industry;
use App\Domain\Company\LegalForm;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\ReportingFrameworkRules;
use App\Domain\Company\VatPeriod;
use App\Domain\Waitlist\OnboardingPlan;
use App\Livewire\Concerns\LooksUpCompanies;
use App\Models\Company;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\CompanyRegistry\CompanySize;
use App\Support\CompanyRegistry\RegisteredCompany;
use App\Support\CompanyRegistry\RegistryCodes;
use App\Support\CompanyRegistry\Sni;
use App\Support\CompanyRegistry\SniIndustry;
use App\Support\Tenancy\SpansCompanies;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

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

    /** @var array<string, string> what the register's suggestion for a field was based on, shown beside the field */
    public array $suggested = [];

    /** @var array<string, bool> fields the user has changed by hand: a lookup never suggests for them again */
    public array $suggestionTouched = [];

    public function updatedIndustry(): void
    {
        $this->suggestionTouched['industry'] = true;
        unset($this->suggested['industry']);
    }

    public function updatedEmployerRegistered(): void
    {
        $this->suggestionTouched['employer_registered'] = true;
        unset($this->suggested['employer_registered']);
    }

    /**
     * Industry from the SNI code and employer from the employee class
     * (ADR-035 addendum): suggestions with the same rule as the other
     * fields, so a field the user has filled in or changed is left alone.
     * Never K3: the K2 hint only warns.
     *
     * @return list<string>
     */
    protected function applyRegistrySuggestions(RegisteredCompany $found): array
    {
        $industry = SniIndustry::for($found->sniCode);

        if ($industry !== null
            && ! ($this->suggestionTouched['industry'] ?? false)
            && ($this->industry === '' || $this->industry === ($this->registryFilled['industry'] ?? null))) {
            $this->industry = $industry->value;
            $this->registryFilled['industry'] = $industry->value;
            $this->suggested['industry'] = (string) Sni::label($found->sniCode);
        }

        $hasEmployees = CompanySize::hasEmployees($found->employeeClass);

        if ($hasEmployees !== null && ! ($this->suggestionTouched['employer_registered'] ?? false)) {
            $this->employer_registered = $hasEmployees;
            $this->suggested['employer_registered'] = (string) RegistryCodes::employees($found->employeeClass);
        }

        if (! CompanySize::likelyAboveK2Limits($found->employeeClass, $found->turnoverClass)) {
            return [];
        }

        return [__('Registret visar :employees och en omsättning på :turnover. Då kan företaget vara ett större företag som inte får använda K2. Läs villkoren under "Regelverk för årsbokslut" innan du väljer.', [
            'employees' => mb_strtolower((string) RegistryCodes::employees($found->employeeClass)),
            'turnover' => mb_strtolower((string) RegistryCodes::turnover($found->turnoverClass)),
        ])];
    }

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
    public function mount(bool $prefillFromWaitlist = false): void
    {
        $prefill = (array) session()->pull('company.prefill', []);
        $this->name = (string) ($prefill['name'] ?? $this->name);
        $this->org_number = (string) ($prefill['org_number'] ?? $this->org_number);

        if ($prefillFromWaitlist && $prefill === []) {
            $this->prefillFromWaitlist();
        }
    }

    /**
     * Someone let in from the waiting list has already told us the company's
     * name, organisation number and industry (ADR-038 addendum). They are put
     * in the form, all of them editable, and the company lookup runs once on the
     * number so the address and legal form are proposed too. Only /kom-igang asks
     * for this, not the page for an additional company.
     */
    private function prefillFromWaitlist(): void
    {
        /** @var ?User $user */
        $user = Auth::user();
        $entry = $user === null ? null : WaitlistEntry::approvedFor($user);

        // Only for the person's first company: not for someone who has already created one since the approval.
        if ($user === null || $entry === null || $user->ownedTeams()->where('created_at', '>=', $entry->decided_at)->exists()) {
            return;
        }

        $this->name = $entry->company_name;
        $this->org_number = (string) $entry->org_number;
        $this->industry = OnboardingPlan::industryFor($entry->industry)->value ?? '';

        $number = OrganisationNumber::tryFrom($this->org_number);

        // The register is asked once per number and session, not on every load of the page; "Hämta uppgifter" asks again.
        if ($number === null || ! $this->companyLookupAvailable() || session()->get('onboarding.looked_up') === $number->digits) {
            return;
        }

        session()->put('onboarding.looked_up', $number->digits);

        try {
            $this->lookUpCompany();
        } catch (Throwable $exception) {
            // The form works without the proposal: the user fills in the rest by hand.
            report($exception);
        }
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

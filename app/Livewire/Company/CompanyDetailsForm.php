<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Company\ChangeReportingFramework;
use App\Actions\Company\UpdateCompanyDetails;
use App\Domain\Accounting\FiscalYearRules;
use App\Domain\Company\AccountingMethod;
use App\Domain\Company\CompanySetupRules;
use App\Domain\Company\Industry;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\ReportingFrameworkRules;
use App\Domain\Company\VatExemption;
use App\Domain\Company\VatPeriod;
use App\Livewire\Concerns\LooksUpCompanies;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Money\Money;
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
            'vat_exemption' => (string) $company->vat_exemption?->value,
            'fiscal_year_start_month' => $company->fiscal_year_start_month,
            'accounting_method' => $company->accounting_method->value,
            'reporting_framework' => $company->reporting_framework->value,
            'f_skatt' => $company->f_skatt,
            'preliminary_tax_monthly' => $company->preliminary_tax_monthly_ore === null ? '' : str_replace("\u{00A0}", ' ', Money::ofOre($company->preliminary_tax_monthly_ore)->format(false)),
            'employer_registered' => $company->employer_registered,
            'turnover_over_40_msek' => $company->turnover_over_40_msek,
            'framework_confirmed' => false,
            'framework_change_reason' => '',
            'framework_from_year' => self::defaultFrameworkYear($company, $company->reporting_framework),
            'calendar_year_exempt' => false,
            'oss_elected' => $company->oss_elected,
            'oss_elected_from' => (string) $company->oss_elected_from?->toDateString(),
            'eu_sales_quarterly_goods' => $company->eu_sales_quarterly_goods,
            'eu_sales_quarterly_goods_from' => (string) $company->eu_sales_quarterly_goods_from?->toDateString(),
            'industry' => (string) $company->industry?->value,
            'guide_enabled' => $company->guide_enabled,
            'mileage_log_enabled' => $company->mileage_log_enabled,
        ];
    }

    public function updatedStateReportingFramework(): void
    {
        $this->state['framework_confirmed'] = false;
        $framework = ReportingFramework::tryFrom((string) ($this->state['reporting_framework'] ?? ''));
        $this->state['framework_from_year'] = $framework === null ? null : self::defaultFrameworkYear($this->company(), $framework);
    }

    public function save(UpdateCompanyDetails $updateCompanyDetails): void
    {
        $this->resetErrorBag();
        $this->keepFrameworkYearEligible($this->company());
        $framework = ReportingFramework::tryFrom((string) ($this->state['reporting_framework'] ?? ''));
        $frameworkChanges = $framework !== null && UpdateCompanyDetails::frameworkWouldChange($this->company(), $framework, $this->state['framework_from_year'] ?? null)
            && $this->yearsChanging($this->company(), $framework);
        $fromLabel = $this->frameworkYears($this->company())[(int) ($this->state['framework_from_year'] ?? 0)] ?? null;

        $updateCompanyDetails($this->user(), $this->company(), $this->state);

        $company = $this->company()->refresh();
        $this->state['bankgiro'] = $company->bankgiro;
        $this->state['plusgiro'] = $company->plusgiro;
        $this->state['preliminary_tax_monthly'] = $company->preliminary_tax_monthly_ore === null ? '' : str_replace("\u{00A0}", ' ', Money::ofOre($company->preliminary_tax_monthly_ore)->format(false));
        $this->state['framework_confirmed'] = false;
        $this->state['framework_change_reason'] = '';
        $this->state['framework_from_year'] = self::defaultFrameworkYear($company, $company->reporting_framework);
        $this->state['calendar_year_exempt'] = false;

        $this->toast($frameworkChanges && $fromLabel !== null
            ? __('Uppgifterna för :company är sparade. Regelverket gäller från räkenskapsåret :year.', ['company' => $company->name, 'year' => $fromLabel])
            : __('Uppgifterna för :company är sparade.', ['company' => $company->name]));
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

        $framework = ReportingFramework::tryFrom((string) ($this->state['reporting_framework'] ?? '')) ?? $company->reporting_framework;
        $month = (int) ($this->state['fiscal_year_start_month'] ?? $company->fiscal_year_start_month);
        $this->keepFrameworkYearEligible($company);
        $frameworkChanged = UpdateCompanyDetails::frameworkWouldChange($company, $framework, $this->state['framework_from_year'] ?? null);

        return view('livewire.company.company-details-form', [
            'company' => $company,
            'warnings' => CompanySetupRules::warnings($company),
            'frameworkRefusal' => $frameworkChanged ? ReportingFrameworkRules::refusal($company->legal_form, $framework) : null,
            'frameworkConditions' => $frameworkChanged ? ReportingFrameworkRules::conditions($company->legal_form, $framework) : null,
            'frameworkChanged' => $frameworkChanged,
            'frameworkYears' => $years = $this->frameworkYears($company),
            'frameworkYear' => $years[(int) ($this->state['framework_from_year'] ?? 0)] ?? (reset($years) ?: null),
            'askPartners' => $month !== $company->fiscal_year_start_month
                && $month !== 1
                && FiscalYearRules::requiresCalendarYearWithNaturalPartner($company->legal_form),
            'canUpdate' => $this->user()->can('update', $company),
            'accountingMethods' => AccountingMethod::cases(),
            'vatPeriods' => VatPeriod::cases(),
            'vatExemptions' => VatExemption::cases(),
            'frameworks' => ReportingFramework::cases(),
            'industries' => Industry::cases(),
            'months' => array_combine(range(1, 12), [
                __('januari'), __('februari'), __('mars'), __('april'), __('maj'), __('juni'),
                __('juli'), __('augusti'), __('september'), __('oktober'), __('november'), __('december'),
            ]),
        ]);
    }

    /**
     * The fiscal years a framework change can start from, id => label
     * (ADR-059, A.2.1).
     *
     * @return array<int, string>
     */
    private function frameworkYears(Company $company): array
    {
        return ChangeReportingFramework::eligibleYears($company)->mapWithKeys(fn (FiscalYear $year): array => [$year->id => $year->label()])->all();
    }

    /**
     * The year preselected for a framework: the earliest year from which
     * nothing would change (so saving other details changes no year), else
     * the earliest eligible year.
     */
    private static function defaultFrameworkYear(Company $company, ReportingFramework $framework): ?int
    {
        $eligible = ChangeReportingFramework::eligibleYears($company);

        foreach ($eligible as $year) {
            if (! ChangeReportingFramework::wouldChange($company, $framework, $year, $eligible)) {
                return $year->id;
            }
        }

        return $eligible->first()?->id;
    }

    /** Whether saving moves at least one fiscal year to the framework, not only the company default. */
    private function yearsChanging(Company $company, ReportingFramework $framework): bool
    {
        $eligible = ChangeReportingFramework::eligibleYears($company);
        $from = $eligible->firstWhere('id', (int) ($this->state['framework_from_year'] ?? 0)) ?? $eligible->first();

        return $from instanceof FiscalYear && ChangeReportingFramework::yearsToChange($framework, $from, $eligible)->isNotEmpty();
    }

    /** A chosen year that is no longer eligible (closed, adopted) falls back to the default. */
    private function keepFrameworkYearEligible(Company $company): void
    {
        if (array_key_exists((int) ($this->state['framework_from_year'] ?? 0), $this->frameworkYears($company))) {
            return;
        }

        $framework = ReportingFramework::tryFrom((string) ($this->state['reporting_framework'] ?? '')) ?? $company->reporting_framework;
        $this->state['framework_from_year'] = self::defaultFrameworkYear($company, $framework);
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

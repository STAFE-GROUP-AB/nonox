<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Actions\Setup\CompleteFirstRun;
use App\Domain\Accounting\FiscalYearRules;
use App\Domain\Company\CompanySetupRules;
use App\Domain\Company\LegalForm;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\ReportingFrameworkRules;
use App\Domain\Company\VatPeriod;
use App\Rules\SwedishOrganisationNumber;
use App\Support\FirstRun;
use App\Support\LoginCodeAutofill;
use App\Support\Tenancy\SpansCompanies;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The first-run guide: company, the person, then a one-time code to confirm
 * the email address, which becomes the sign-in address.
 */
class FirstRunWizard extends Component implements SpansCompanies
{
    private const string VERIFIED_EMAIL = 'setup.email';

    #[Locked]
    public string $step = 'company';

    public string $company_name = '';

    public string $org_number = '';

    public string $legal_form = 'AB';

    public string $vat = 'QUARTERLY';

    public int $fiscal_year_start_month = 1;

    public string $reporting_framework = 'K2';

    public bool $framework_confirmed = false;

    public bool $calendar_year_exempt = false;

    public string $name = '';

    public string $email = '';

    public string $code = '';

    public function toPerson(): void
    {
        if (! $this->stillAllowed()) {
            return;
        }

        $number = OrganisationNumber::tryFrom($this->org_number);

        if ($number !== null) {
            $this->org_number = $number->formatted();
        }

        $dependent = CompanySetupRules::rules([
            'legal_form' => $this->legal_form,
            'reporting_framework' => $this->reporting_framework,
            'fiscal_year_start_month' => $this->fiscal_year_start_month,
        ]);

        $this->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'org_number' => ['required', 'string', new SwedishOrganisationNumber, Rule::unique('teams', 'org_number')],
            'legal_form' => ['required', Rule::enum(LegalForm::class)],
            'vat' => ['required', Rule::enum(VatPeriod::class)],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12', ...$dependent['fiscal_year_start_month']],
            'reporting_framework' => ['required', Rule::enum(ReportingFramework::class), ...$dependent['reporting_framework']],
            'framework_confirmed' => $dependent['framework_confirmed'],
            'calendar_year_exempt' => $dependent['calendar_year_exempt'],
        ], [
            ...CompanySetupRules::messages(),
            'org_number.unique' => __('Det finns redan ett företag med det här organisationsnumret.'),
        ], [
            'company_name' => __('företagets namn'),
            'org_number' => __('organisationsnummer'),
        ]);

        $this->step = 'person';
    }

    public function updatedReportingFramework(): void
    {
        $this->framework_confirmed = false;
    }

    public function updatedLegalForm(): void
    {
        $this->framework_confirmed = false;

        if (FiscalYearRules::requiresCalendarYear(LegalForm::from($this->legal_form))) {
            $this->fiscal_year_start_month = 1;
        }
    }

    public function backToCompany(): void
    {
        $this->step = 'company';
    }

    public function sendCode(SendLoginCode $sendLoginCode, VerifyLoginCode $verifyLoginCode, CompleteFirstRun $completeFirstRun): void
    {
        if (! $this->stillAllowed()) {
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ], attributes: [
            'name' => __('ditt namn'),
            'email' => __('din e-postadress'),
        ]);

        $email = SendLoginCode::normalise($this->email);
        $sendLoginCode($email, request()->ip());

        session()->put(self::VERIFIED_EMAIL, $email);
        $this->email = $email;
        $this->code = '';
        $this->step = 'code';

        $autofilledCode = LoginCodeAutofill::code($email);

        if ($autofilledCode !== null) {
            $this->code = $autofilledCode;
            $this->finish($verifyLoginCode, $completeFirstRun);
        }
    }

    public function finish(VerifyLoginCode $verifyLoginCode, CompleteFirstRun $completeFirstRun): void
    {
        if (! $this->stillAllowed()) {
            return;
        }

        $email = session()->get(self::VERIFIED_EMAIL);

        if (! is_string($email)) {
            $this->step = 'person';

            return;
        }

        $this->validate(
            ['code' => ['required', 'regex:/^\s*(\d\s*){'.config('auth.login_codes.length').'}$/']],
            ['code.regex' => __('Koden består av :count siffror.', ['count' => config('auth.login_codes.length')])],
            ['code' => __('kod')],
        );

        if (! $verifyLoginCode($email, $this->code)) {
            $this->addError('code', __('Koden stämmer inte eller har slutat gälla. Kontrollera mejlet eller skicka en ny kod.'));

            return;
        }

        $vatRegistered = $this->vat !== VatPeriod::None->value;

        $user = $completeFirstRun($this->name, $email, [
            'name' => $this->company_name,
            'org_number' => $this->org_number,
            'legal_form' => $this->legal_form,
            'fiscal_year_start_month' => $this->fiscal_year_start_month,
            'accounting_method' => 'FAKTURAMETODEN',
            'vat_registered' => $vatRegistered,
            'vat_period' => $this->vat,
            'reporting_framework' => $this->reporting_framework,
            'framework_confirmed' => $this->framework_confirmed,
            'calendar_year_exempt' => $this->calendar_year_exempt,
            'f_skatt' => true,
            'employer_registered' => false,
        ]);

        session()->forget(self::VERIFIED_EMAIL);

        Auth::login($user);
        session()->regenerate();

        Toast::flash(__('Välkommen! :company är skapat och du är inloggad.', ['company' => $this->company_name]));

        $this->redirectRoute('dashboard');
    }

    public function render(): View
    {
        $legalForm = LegalForm::tryFrom($this->legal_form) ?? LegalForm::Ab;
        $framework = ReportingFramework::tryFrom($this->reporting_framework) ?? ReportingFramework::K2;

        return view('livewire.setup.first-run-wizard', [
            'legalForms' => LegalForm::cases(),
            'frameworks' => ReportingFramework::cases(),
            'frameworkRefusal' => ReportingFrameworkRules::refusal($legalForm, $framework),
            'frameworkConditions' => ReportingFrameworkRules::conditions($legalForm, $framework),
            'calendarYearOnly' => FiscalYearRules::requiresCalendarYear($legalForm),
            'askPartners' => FiscalYearRules::requiresCalendarYearWithNaturalPartner($legalForm) && $this->fiscal_year_start_month !== 1,
            'autofill' => LoginCodeAutofill::enabled(),
            'months' => array_combine(range(1, 12), [
                __('januari'), __('februari'), __('mars'), __('april'), __('maj'), __('juni'),
                __('juli'), __('augusti'), __('september'), __('oktober'), __('november'), __('december'),
            ]),
        ]);
    }

    private function stillAllowed(): bool
    {
        if (FirstRun::allowed()) {
            return true;
        }

        $this->redirect('/');

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Domain\Company\AccountingMethod;
use App\Domain\Company\CompanySetupRules;
use App\Domain\Company\Industry;
use App\Domain\Company\LegalForm;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatPeriod;
use App\Http\Controllers\LegalPageController;
use App\Models\Company;
use App\Models\User;
use App\Rules\SwedishOrganisationNumber;
use App\Support\Audit\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\CreatesTeams;
use Laravel\Jetstream\Events\AddingTeam;

/**
 * Creates a company and makes the creator its owner (AGENTS.md §3.4).
 */
class CreateCompany implements CreatesTeams
{
    public const string BAS_VERSION = 'BAS 2026';

    public function __construct(private InitialiseBookkeeping $initialiseBookkeeping) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(User $user, array $input): Company
    {
        Gate::forUser($user)->authorize('create', Company::class);

        $validated = $this->validate($input, $user);

        AddingTeam::dispatch($user);

        return DB::transaction(function () use ($user, $validated): Company {
            /** @var Company $company */
            $company = $user->ownedTeams()->create($this->attributes($validated));

            // AGENTS.md §3.4 steps 3-5: chart of accounts, first fiscal year,
            // default voucher series.
            ($this->initialiseBookkeeping)($company, $user);

            // TODO(T-1.5): default VAT codes (§3.4 step 6).
            // TODO(Phase 2): number series for invoices and customers (§3.4 step 7).

            if (config('app.landing.hosted')) {
                $company->forceFill([
                    'agreement_accepted_at' => now(),
                    'agreement_version' => LegalPageController::VERSION,
                    'agreement_accepted_by' => $user->id,
                ])->save();
            }

            Audit::record('company.created', $company, [
                'name' => $company->name,
                'org_number' => $company->org_number,
                'legal_form' => $company->legal_form->value,
                'reporting_framework' => $company->reporting_framework->value,
                'framework_conditions_confirmed' => $validated['framework_confirmed'] ?? false,
                'calendar_year_exempt_confirmed' => $validated['calendar_year_exempt'] ?? false,
                'agreement_version' => $company->agreement_version,
                'industry' => $company->industry?->value,
                'started_on' => $company->started_on?->toDateString(),
            ], $company, $user);

            $user->switchCompany($company);

            return $company;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $input, User $user): array
    {
        $number = is_string($input['org_number'] ?? null)
            ? OrganisationNumber::tryFrom($input['org_number'])
            : null;

        if ($number !== null) {
            $input['org_number'] = $number->formatted();
        }

        $dependent = CompanySetupRules::rules($input);

        return Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'org_number' => [
                'required',
                'string',
                new SwedishOrganisationNumber,
                $this->uniqueOrgNumber($user),
            ],
            'legal_form' => ['required', Rule::enum(LegalForm::class)],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12', ...$dependent['fiscal_year_start_month']],
            'accounting_method' => ['required', Rule::enum(AccountingMethod::class)],
            'vat_registered' => ['required', 'boolean'],
            'vat_period' => ['required', Rule::enum(VatPeriod::class)],
            'reporting_framework' => ['required', Rule::enum(ReportingFramework::class), ...$dependent['reporting_framework']],
            'framework_confirmed' => $dependent['framework_confirmed'],
            'calendar_year_exempt' => $dependent['calendar_year_exempt'],
            'f_skatt' => ['required', 'boolean'],
            'employer_registered' => ['required', 'boolean'],
            'accept_agreement' => config('app.landing.hosted') ? ['accepted'] : ['nullable'],
            'industry' => ['nullable', Rule::enum(Industry::class)],
            'started_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addYear()->toDateString()],
        ], [
            ...CompanySetupRules::messages(),
            'org_number.unique' => __('Det finns redan ett företag med det här organisationsnumret.'),
            'accept_agreement.accepted' => __('Godkänn villkoren och personuppgiftsbiträdesavtalet för att skapa företaget.'),
        ], [
            'name' => __('företagsnamn'),
            'org_number' => __('organisationsnummer'),
            'legal_form' => __('företagsform'),
            'fiscal_year_start_month' => __('räkenskapsårets första månad'),
            'accounting_method' => __('bokföringsmetod'),
            'vat_period' => __('momsperiod'),
            'reporting_framework' => __('regelverk'),
            'industry' => __('bransch'),
            'started_on' => __('datum då företaget startade'),
        ])->validateWithBag('createTeam');
    }

    /**
     * Self-hosted: one organisation number per installation. Hosted: only
     * within the companies this user already belongs to, so nobody can lock
     * a real company out by registering its number first.
     */
    private function uniqueOrgNumber(User $user): Unique
    {
        $rule = Rule::unique('teams', 'org_number');

        if (! config('app.landing.hosted')) {
            return $rule;
        }

        return $rule->where(fn (Builder $query) => $query->where(fn (Builder $mine) => $mine
            ->where('user_id', $user->id)
            ->orWhereIn('id', DB::table('team_user')->where('user_id', $user->id)->select('team_id'))));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $number = OrganisationNumber::tryFrom((string) $validated['org_number']);
        $vatRegistered = (bool) $validated['vat_registered'];

        unset($validated['framework_confirmed'], $validated['calendar_year_exempt']);

        return [
            ...$validated,
            'personal_team' => false,
            'legal_name' => $validated['legal_name'] ?? $validated['name'],
            'vat_number' => $vatRegistered ? $number?->vatNumber() : null,
            'vat_period' => $vatRegistered ? $validated['vat_period'] : VatPeriod::None->value,
            'bas_version' => self::BAS_VERSION,
            'country' => 'SE',
            'base_currency' => 'SEK',
        ];
    }
}

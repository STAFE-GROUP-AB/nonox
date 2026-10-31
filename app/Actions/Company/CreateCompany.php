<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Domain\Company\AccountingMethod;
use App\Domain\Company\LegalForm;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatPeriod;
use App\Http\Controllers\LegalPageController;
use App\Models\Company;
use App\Models\User;
use App\Rules\SwedishOrganisationNumber;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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

        $validated = $this->validate($input);

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
                'agreement_version' => $company->agreement_version,
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
    private function validate(array $input): array
    {
        $number = is_string($input['org_number'] ?? null)
            ? OrganisationNumber::tryFrom($input['org_number'])
            : null;

        if ($number !== null) {
            $input['org_number'] = $number->formatted();
        }

        return Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'org_number' => [
                'required',
                'string',
                new SwedishOrganisationNumber,
                Rule::unique('teams', 'org_number'),
            ],
            'legal_form' => ['required', Rule::enum(LegalForm::class)],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'accounting_method' => ['required', Rule::enum(AccountingMethod::class)],
            'vat_registered' => ['required', 'boolean'],
            'vat_period' => ['required', Rule::enum(VatPeriod::class)],
            // TODO(legal-verify): which legal forms and company sizes may use K1, K2 and K3?
            // Any combination is accepted until that is confirmed.
            'reporting_framework' => ['required', Rule::enum(ReportingFramework::class)],
            'f_skatt' => ['required', 'boolean'],
            'employer_registered' => ['required', 'boolean'],
            'accept_agreement' => config('app.landing.hosted') ? ['accepted'] : ['nullable'],
        ], [
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
        ])->validateWithBag('createTeam');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $number = OrganisationNumber::tryFrom((string) $validated['org_number']);
        $vatRegistered = (bool) $validated['vat_registered'];

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

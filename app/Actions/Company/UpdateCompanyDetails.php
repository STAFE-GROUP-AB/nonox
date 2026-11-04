<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Domain\Company\AccountingMethod;
use App\Domain\Company\CompanySetupRules;
use App\Domain\Company\GiroNumber;
use App\Domain\Company\Industry;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatExemption;
use App\Domain\Company\VatPeriod;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Updates a company's details. The organisation number and legal form are
 * not editable: changing them would make it a different company.
 */
class UpdateCompanyDetails
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, array $input): void
    {
        Gate::forUser($user)->authorize('update', $company);

        $dependent = $this->dependentRules($company, $input);
        foreach (['oss_elected_from', 'eu_sales_quarterly_goods_from', 'industry'] as $optional) {
            if (($input[$optional] ?? null) === '') {
                $input[$optional] = null;
            }
        }

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'bankgiro' => ['nullable', 'string', $this->giro(GiroNumber::bankgiro(...), __('Bankgironumret stämmer inte. Kontrollera siffrorna: den sista är en kontrollsiffra som avslöjar skrivfel. Ett bankgironummer har sju eller åtta siffror, till exempel 123-4566.'))],
            'plusgiro' => ['nullable', 'string', $this->giro(GiroNumber::plusgiro(...), __('Plusgironumret stämmer inte. Kontrollera siffrorna: den sista är en kontrollsiffra som avslöjar skrivfel. Till exempel 123456-6.'))],
            'vat_period' => ['required', Rule::enum(VatPeriod::class)],
            'vat_exemption' => ['sometimes', 'nullable', Rule::enum(VatExemption::class)],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12', ...$dependent['fiscal_year_start_month']],
            'accounting_method' => ['required', Rule::enum(AccountingMethod::class)],
            'reporting_framework' => ['required', Rule::enum(ReportingFramework::class), ...$dependent['reporting_framework']],
            'framework_confirmed' => $dependent['framework_confirmed'],
            'calendar_year_exempt' => $dependent['calendar_year_exempt'],
            'f_skatt' => ['required', 'boolean'],
            'employer_registered' => ['required', 'boolean'],
            'turnover_over_40_msek' => ['sometimes', 'boolean'],
            'oss_elected' => ['sometimes', 'boolean'],
            'oss_elected_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'eu_sales_quarterly_goods' => ['sometimes', 'boolean'],
            'eu_sales_quarterly_goods_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'industry' => ['sometimes', 'nullable', Rule::enum(Industry::class)],
            'guide_enabled' => ['sometimes', 'boolean'],
        ], CompanySetupRules::messages(), [
            'oss_elected_from' => __('datum för beskattning i köparens land'),
            'eu_sales_quarterly_goods_from' => __('första kvartalet beslutet gäller'),
            'name' => __('företagsnamn'),
            'website' => __('webbplats'),
            'industry' => __('bransch'),
        ])->validateWithBag('updateCompanyDetails');

        $confirmed = [
            'framework_conditions_confirmed' => (bool) ($validated['framework_confirmed'] ?? false),
            'calendar_year_exempt_confirmed' => (bool) ($validated['calendar_year_exempt'] ?? false),
        ];
        unset($validated['framework_confirmed'], $validated['calendar_year_exempt']);

        $before = $this->snapshot($company);
        $vatRegistered = $validated['vat_period'] !== VatPeriod::None->value;
        $number = OrganisationNumber::tryFrom($company->org_number);

        $company->forceFill([
            ...$validated,
            'bankgiro' => filled($validated['bankgiro'] ?? null) ? GiroNumber::bankgiro((string) $validated['bankgiro']) : null,
            'plusgiro' => filled($validated['plusgiro'] ?? null) ? GiroNumber::plusgiro((string) $validated['plusgiro']) : null,
            'vat_registered' => $vatRegistered,
            'vat_number' => $vatRegistered ? $number?->vatNumber() : null,
            // Why a company is not registered matters only while it is not (#51).
            'vat_exemption' => $vatRegistered ? null : (array_key_exists('vat_exemption', $validated) ? VatExemption::tryFrom((string) $validated['vat_exemption']) : $company->vat_exemption),
            'oss_elected_from' => (bool) ($validated['oss_elected'] ?? $company->oss_elected) ? ($validated['oss_elected_from'] ?? $company->oss_elected_from) : null,
            'eu_sales_quarterly_goods_from' => $this->quarterlyGoodsFrom($company, $validated),
        ])->save();

        $changes = Audit::changes($before, $this->snapshot($company));

        if ($changes['after'] !== []) {
            Audit::record('company.updated', $company, [...$changes, ...array_filter($confirmed)], $company);
        }
    }

    /**
     * The legal-form rules apply to a value the user changes. A value that
     * was saved before the rules existed is kept, and the settings page
     * shows a warning instead (enligt AI-utredning 2026-11-02,
     * juristgranskning återstår).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    private function dependentRules(Company $company, array $input): array
    {
        $month = is_numeric($input['fiscal_year_start_month'] ?? null) ? (int) $input['fiscal_year_start_month'] : null;
        $framework = is_string($input['reporting_framework'] ?? null) ? ReportingFramework::tryFrom($input['reporting_framework']) : null;
        $monthChanged = $month !== null && $month !== $company->fiscal_year_start_month;
        $frameworkChanged = $framework !== null && $framework !== $company->reporting_framework;

        $rules = CompanySetupRules::rules([
            'legal_form' => $company->legal_form->value,
            'reporting_framework' => $framework?->value,
            'fiscal_year_start_month' => $month,
        ]);

        return [
            'fiscal_year_start_month' => $monthChanged ? $rules['fiscal_year_start_month'] : [],
            'reporting_framework' => $frameworkChanged ? $rules['reporting_framework'] : [],
            'framework_confirmed' => $frameworkChanged ? $rules['framework_confirmed'] : ['nullable', 'boolean'],
            'calendar_year_exempt' => $monthChanged ? $rules['calendar_year_exempt'] : ['nullable', 'boolean'],
        ];
    }

    /**
     * The decision on quarterly reporting of goods applies from the start of
     * a quarter (SFL 35 kap. 6 §), so a date is moved to its quarter's first
     * day; without a decision there is no date.
     *
     * @param  array<string, mixed>  $validated
     */
    private function quarterlyGoodsFrom(Company $company, array $validated): ?string
    {
        if (! (bool) ($validated['eu_sales_quarterly_goods'] ?? $company->eu_sales_quarterly_goods)) {
            return null;
        }

        $from = array_key_exists('eu_sales_quarterly_goods_from', $validated) ? $validated['eu_sales_quarterly_goods_from'] : $company->eu_sales_quarterly_goods_from?->toDateString();

        return is_string($from) ? CarbonImmutable::parse($from)->firstOfQuarter()->toDateString() : null;
    }

    /** @return array<string, mixed> */
    private function snapshot(Company $company): array
    {
        return collect($company->only([
            'name', 'address_line1', 'postal_code', 'city', 'email', 'phone', 'website',
            'bankgiro', 'plusgiro', 'vat_registered', 'vat_number', 'vat_period', 'vat_exemption',
            'fiscal_year_start_month', 'accounting_method', 'reporting_framework',
            'f_skatt', 'employer_registered', 'turnover_over_40_msek', 'oss_elected', 'oss_elected_from',
            'eu_sales_quarterly_goods', 'eu_sales_quarterly_goods_from',
            'industry', 'guide_enabled',
        ]))->map(fn (mixed $value) => match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => $value,
        })->all();
    }

    /**
     * @param  Closure(string): ?string  $normalise
     */
    private function giro(Closure $normalise, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($normalise, $message): void {
            if (is_string($value) && $value !== '' && $normalise($value) === null) {
                $fail($message);
            }
        };
    }
}

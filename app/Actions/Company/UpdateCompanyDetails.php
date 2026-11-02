<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Domain\Company\AccountingMethod;
use App\Domain\Company\GiroNumber;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatPeriod;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
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

        if (($input['oss_elected_from'] ?? null) === '') {
            $input['oss_elected_from'] = null;
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
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'accounting_method' => ['required', Rule::enum(AccountingMethod::class)],
            'reporting_framework' => ['required', Rule::enum(ReportingFramework::class)],
            'f_skatt' => ['required', 'boolean'],
            'employer_registered' => ['required', 'boolean'],
            'turnover_over_40_msek' => ['sometimes', 'boolean'],
            'oss_elected' => ['sometimes', 'boolean'],
            'oss_elected_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ], attributes: [
            'oss_elected_from' => __('datum för beskattning i köparens land'),
            'name' => __('företagsnamn'),
            'website' => __('webbplats'),
        ])->validateWithBag('updateCompanyDetails');

        $before = $this->snapshot($company);
        $vatRegistered = $validated['vat_period'] !== VatPeriod::None->value;
        $number = OrganisationNumber::tryFrom($company->org_number);

        $company->forceFill([
            ...$validated,
            'bankgiro' => filled($validated['bankgiro'] ?? null) ? GiroNumber::bankgiro((string) $validated['bankgiro']) : null,
            'plusgiro' => filled($validated['plusgiro'] ?? null) ? GiroNumber::plusgiro((string) $validated['plusgiro']) : null,
            'vat_registered' => $vatRegistered,
            'vat_number' => $vatRegistered ? $number?->vatNumber() : null,
            'oss_elected_from' => (bool) ($validated['oss_elected'] ?? $company->oss_elected) ? ($validated['oss_elected_from'] ?? $company->oss_elected_from) : null,
        ])->save();

        $changes = Audit::changes($before, $this->snapshot($company));

        if ($changes['after'] !== []) {
            Audit::record('company.updated', $company, $changes, $company);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Company $company): array
    {
        return collect($company->only([
            'name', 'address_line1', 'postal_code', 'city', 'email', 'phone', 'website',
            'bankgiro', 'plusgiro', 'vat_registered', 'vat_number', 'vat_period',
            'fiscal_year_start_month', 'accounting_method', 'reporting_framework',
            'f_skatt', 'employer_registered', 'turnover_over_40_msek', 'oss_elected', 'oss_elected_from',
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

<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Company\AccountingMethod;
use App\Domain\Company\CompanySetupRules;
use App\Domain\Company\GiroNumber;
use App\Domain\Company\Industry;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatExemption;
use App\Domain\Company\VatPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CompanyScope;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
        foreach (['oss_elected_from', 'eu_sales_quarterly_goods_from', 'industry', 'preliminary_tax_monthly'] as $optional) {
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
            'preliminary_tax_monthly' => ['sometimes', 'nullable', 'string', 'max:20', $this->amount()],
            'employer_registered' => ['required', 'boolean'],
            'turnover_over_40_msek' => ['sometimes', 'boolean'],
            'oss_elected' => ['sometimes', 'boolean'],
            'oss_elected_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'eu_sales_quarterly_goods' => ['sometimes', 'boolean'],
            'eu_sales_quarterly_goods_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'industry' => ['sometimes', 'nullable', Rule::enum(Industry::class)],
            'guide_enabled' => ['sometimes', 'boolean'],
            'framework_change_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], CompanySetupRules::messages(), [
            'oss_elected_from' => __('datum för beskattning i köparens land'),
            'eu_sales_quarterly_goods_from' => __('första kvartalet beslutet gäller'),
            'name' => __('företagsnamn'),
            'website' => __('webbplats'),
            'industry' => __('bransch'),
            'preliminary_tax_monthly' => __('debiterad preliminärskatt per månad'),
            'framework_change_reason' => __('anledning'),
        ])->validateWithBag('updateCompanyDetails');

        $confirmed = [
            'framework_conditions_confirmed' => (bool) ($validated['framework_confirmed'] ?? false),
            'calendar_year_exempt_confirmed' => (bool) ($validated['calendar_year_exempt'] ?? false),
        ];
        $reason = is_string($validated['framework_change_reason'] ?? null) ? $validated['framework_change_reason'] : null;
        unset($validated['framework_confirmed'], $validated['calendar_year_exempt'], $validated['framework_change_reason']);

        if (array_key_exists('preliminary_tax_monthly', $validated)) {
            // Momsbufferten (ADR-051, stage 3): the amount from the decision, as the company enters it; NONOX never computes it.
            $validated['preliminary_tax_monthly_ore'] = filled($validated['preliminary_tax_monthly']) ? Money::parse((string) $validated['preliminary_tax_monthly'])->ore : null;
            unset($validated['preliminary_tax_monthly']);
        }

        $before = $this->snapshot($company);

        DB::transaction(function () use ($user, $company, $validated, $confirmed, $reason, $before): void {
            $this->save($user, $company, $validated, $confirmed, $reason, $before);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{framework_conditions_confirmed: bool, calendar_year_exempt_confirmed: bool}  $confirmed
     * @param  array<string, mixed>  $before
     */
    private function save(User $user, Company $company, array $validated, array $confirmed, ?string $reason, array $before): void
    {
        $framework = ReportingFramework::from((string) $validated['reporting_framework']);

        if ($framework !== $company->reporting_framework && $this->changeFrameworkOfLatestYear($user, $company, $framework, $reason, $confirmed['framework_conditions_confirmed'])) {
            // ChangeReportingFramework has set the company column as the latest year's framework.
            $company->refresh();
            unset($validated['reporting_framework']);
        }

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

        if ($company->reporting_framework === ReportingFramework::K2 && $confirmed['framework_conditions_confirmed'] && $company->k2_conditions_confirmed_at === null) {
            // The K2 conditions were shown and confirmed in the form (owner decision 16): not asked again in the closing.
            $company->forceFill(['k2_conditions_confirmed_at' => now(), 'k2_conditions_confirmed_by' => $user->id])->save();
        }

        $changes = self::withoutAmounts(Audit::changes($before, $this->snapshot($company)));

        if ($changes['after'] !== []) {
            Audit::record('company.updated', $company, [...$changes, ...array_filter($confirmed)], $company);
        }
    }

    /**
     * The framework belongs to the fiscal year (ADR-059, A.2.1). A change on
     * the settings page applies to the latest open year, and from there to
     * the years that follow it; earlier years keep theirs. A company without
     * fiscal years only has the default, which is changed as before (false).
     *
     * @throws ValidationException when every year is closed
     */
    private function changeFrameworkOfLatestYear(User $user, Company $company, ReportingFramework $framework, ?string $reason, bool $confirmed): bool
    {
        // Safe: limited to this company explicitly; the action may run without a current company.
        [$hasYears, $latestOpen] = CompanyScope::bypass(fn (): array => [
            FiscalYear::query()->where('company_id', $company->id)->exists(),
            FiscalYear::query()->where('company_id', $company->id)->where('status', FiscalYearStatus::Open)->orderByDesc('start_date')->first(),
        ]);

        if (! $hasYears) {
            return false;
        }

        if (! $latestOpen instanceof FiscalYear) {
            throw ValidationException::withMessages([
                'reporting_framework' => __('Alla räkenskapsår är stängda. Skapa nästa räkenskapsår under Räkenskapsår och byt sedan regelverk.'),
            ])->errorBag('updateCompanyDetails');
        }

        try {
            app(ChangeReportingFramework::class)($user, $latestOpen, $framework, $reason, ['framework_confirmed' => $confirmed]);
        } catch (ValidationException $exception) {
            throw $exception->errorBag('updateCompanyDetails');
        }

        return true;
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
            'f_skatt', 'preliminary_tax_monthly_ore', 'employer_registered', 'turnover_over_40_msek', 'oss_elected', 'oss_elected_from',
            'eu_sales_quarterly_goods', 'eu_sales_quarterly_goods_from',
            'industry', 'guide_enabled',
        ]))->map(fn (mixed $value) => match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => $value,
        })->all();
    }

    /** The most a monthly amount may be (100 000 000 kronor), far above any real decision and far from an integer overflow. */
    public const int MAX_PRELIMINARY_TAX_ORE = 10_000_000_000;

    /**
     * The audit log records that the monthly preliminary tax was set, changed
     * or cleared, never the amount: for a sole trader it is tax data about the
     * owner, and the log is append-only (AGENTS.md §6.6, QUICKSTART §6).
     *
     * @param  array{before: array<string, mixed>, after: array<string, mixed>}  $changes
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private static function withoutAmounts(array $changes): array
    {
        $key = 'preliminary_tax_monthly_ore';

        if (! array_key_exists($key, $changes['after'])) {
            return $changes;
        }

        $before = $changes['before'][$key] ?? null;
        $after = $changes['after'][$key];
        $changes['before'][$key] = $before === null ? null : 'set';
        $changes['after'][$key] = match (true) {
            $after === null => 'cleared',
            $before === null => 'set',
            default => 'changed',
        };

        return $changes;
    }

    /** An amount in kronor, not negative and at most MAX_PRELIMINARY_TAX_ORE, as Money::parse reads it. */
    private function amount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            // A number too long for öre in a bigint is refused here, never a server error (Brick throws a MathException).
            if (strlen((string) preg_replace('/\D/', '', $value)) > 15) {
                $fail(__('Beloppet kan vara högst 100 000 000 kronor i månaden.'));

                return;
            }

            try {
                $amount = Money::parse($value);
            } catch (InvalidArgumentException|MathException) {
                $fail(__('Skriv beloppet i kronor, till exempel 4 500.'));

                return;
            }

            if ($amount->isNegative()) {
                $fail(__('Beloppet kan inte vara negativt.'));

                return;
            }

            if ($amount->ore > self::MAX_PRELIMINARY_TAX_ORE) {
                $fail(__('Beloppet kan vara högst 100 000 000 kronor i månaden.'));
            }
        };
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

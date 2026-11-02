<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Domain\Accounting\FiscalYearRules;
use App\Models\Company;
use Closure;

/**
 * Validation that depends on the legal form: the calendar year for natural
 * persons (BFL 3 kap. 1 §) and the K-regelverk (ReportingFrameworkRules).
 * Used when a company is created, from the form and from the first-run
 * guide. Enligt AI-utredning 2026-11-02, juristgranskning återstår.
 */
final class CompanySetupRules
{
    /**
     * Extra rules for a new company, keyed by field.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    public static function rules(array $input): array
    {
        $legalForm = LegalForm::tryFrom(self::string($input['legal_form'] ?? null));
        $framework = ReportingFramework::tryFrom(self::string($input['reporting_framework'] ?? null));
        $month = is_numeric($input['fiscal_year_start_month'] ?? null) ? (int) $input['fiscal_year_start_month'] : 1;

        $needsFrameworkConfirmation = $legalForm !== null
            && $framework !== null
            && ReportingFrameworkRules::refusal($legalForm, $framework) === null
            && ReportingFrameworkRules::conditions($legalForm, $framework) !== null;

        $needsPartnerConfirmation = $legalForm !== null
            && FiscalYearRules::requiresCalendarYearWithNaturalPartner($legalForm)
            && $month !== 1;

        return [
            'fiscal_year_start_month' => [self::calendarYear($legalForm)],
            'reporting_framework' => [self::framework($legalForm)],
            'framework_confirmed' => $needsFrameworkConfirmation ? ['accepted'] : ['nullable', 'boolean'],
            'calendar_year_exempt' => $needsPartnerConfirmation ? ['accepted'] : ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'framework_confirmed.accepted' => __('Bekräfta att företaget uppfyller villkoren för regelverket, eller välj K3.'),
            'calendar_year_exempt.accepted' => __('Bekräfta att ingen fysisk person beskattas för bolagets inkomst, eller låt räkenskapsåret börja i januari.'),
        ];
    }

    /** A natural person must use the calendar year (BFL 3 kap. 1 §). */
    public static function calendarYear(?LegalForm $legalForm): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($legalForm): void {
            if ($legalForm !== null && FiscalYearRules::requiresCalendarYear($legalForm) && (int) $value !== 1) {
                $fail(__('En enskild firma ska ha kalenderår som räkenskapsår. Välj januari.'));
            }
        };
    }

    public static function framework(?LegalForm $legalForm): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($legalForm): void {
            $framework = ReportingFramework::tryFrom(self::string($value));

            if ($legalForm === null || $framework === null) {
                return;
            }

            $refusal = ReportingFrameworkRules::refusal($legalForm, $framework);

            if ($refusal !== null) {
                $fail($refusal);
            }
        };
    }

    /**
     * What looks wrong with a company's saved choices. Nothing is blocked
     * or changed: existing data stays as it is.
     *
     * @return list<string>
     */
    public static function warnings(Company $company): array
    {
        $warnings = [];
        $refusal = ReportingFrameworkRules::refusal($company->legal_form, $company->reporting_framework);

        if ($refusal !== null) {
            $warnings[] = __('Regelverket :framework ser inte ut att passa företagsformen. :reason', [
                'framework' => $company->reporting_framework->value,
                'reason' => $refusal,
            ]);
        }

        if (FiscalYearRules::requiresCalendarYear($company->legal_form) && $company->fiscal_year_start_month !== 1) {
            $warnings[] = __('En enskild firma ska ha kalenderår som räkenskapsår, men räkenskapsåret är inställt på att börja i en annan månad än januari. Nya räkenskapsår följer kalenderåret.');
        }

        return $warnings;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}

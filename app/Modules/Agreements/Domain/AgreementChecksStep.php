<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The agreement's checks on a payslip, as warnings on the run (ADR-033,
 * step A4): pay below the minimum wage for the employment's group, age and
 * experience, a missing lönegrupp, youth pay past the age it applies to,
 * and an agreement line on a date that is not the day type the line is for
 * (storhelg, say). Never changes an amount. Runs only when the employment
 * is bound to an agreement (AgreementContextExtension).
 */
final class AgreementChecksStep implements Step
{
    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $agreement = $context->extension(AgreementContext::KEY);

        if (! $agreement instanceof AgreementContext) {
            return;
        }

        $this->dayTypes($context, $draft);

        if (! $agreement->minimumWageWarnings) {
            return;
        }

        $version = $agreement->versionOn($context->periodEnd);

        if ($version === null) {
            return;
        }

        $rules = $version['rules'];

        if ($rules->minimumWages() === []) {
            return;
        }

        $check = MinimumWageCheck::check($rules, $agreement->group, $context->birthDate, $context->periodEnd, $agreement->experienceFrom, $agreement->employmentStart, $context->salaryForm, $context->monthlySalaryOre, $context->hourlyRateOre);

        $warning = self::warning($check, $rules->shortName());

        if ($warning !== null) {
            $draft->warnings[] = $warning;
        }
    }

    /**
     * The warning for a minimum wage check, in Swedish, or null when there
     * is nothing to say.
     *
     * @param  array{status: string, age: int, experience_months: int, tenure_months: int, minimum_ore: ?int, actual_ore: ?int, basis: string, row: ?array<string, mixed>, step_from: ?string, group: ?array<string, mixed>, youth_too_old: bool}  $check
     */
    public static function warning(array $check, string $agreement): ?string
    {
        $groupName = (string) ($check['group']['name'] ?? '');

        $message = match (true) {
            $check['status'] === MinimumWageCheck::NO_GROUP => __('Lönegrupp saknas på anställningen, så minimilönen enligt :agreement kan inte kontrolleras. Ange lönegruppen under Lön → Avtal.', ['agreement' => $agreement]),
            $check['status'] === MinimumWageCheck::UNKNOWN_GROUP => __('Lönegruppen på anställningen finns inte i :agreement. Välj en av avtalets lönegrupper under Lön → Avtal.', ['agreement' => $agreement]),
            $check['status'] === MinimumWageCheck::BELOW => __(':pay är under minimilönen :minimum enligt :agreement för :group (:age år:experience). Källa: :source.', [
                'pay' => self::amount((int) $check['actual_ore'], $check['basis']),
                'minimum' => self::amount((int) $check['minimum_ore'], $check['basis']),
                'agreement' => $agreement,
                'group' => $groupName,
                'age' => $check['age'],
                'experience' => $check['experience_months'] > 0 ? __(', :months månaders branschvana', ['months' => $check['experience_months']]) : '',
                'source' => (string) ($check['row']['source'] ?? ''),
            ]),
            $check['youth_too_old'] => __(':group i :agreement gäller bara under :age år. Byt lönegrupp under Lön → Avtal.', ['group' => $groupName, 'agreement' => $agreement, 'age' => (int) ($check['group']['max_age_exclusive'] ?? 0)]),
            default => null,
        };

        return is_string($message) ? $message : null;
    }

    private static function amount(int $ore, string $basis): string
    {
        $formatted = Money::ofOre($ore)->format();

        return $basis === 'hourly_ore' ? __(':amount per timme', ['amount' => $formatted]) : __(':amount per månad', ['amount' => $formatted]);
    }

    /** A line for a day type (storhelg, say) on a date that is not such a day. */
    private function dayTypes(PayrollContext $context, PayslipDraft $draft): void
    {
        $agreement = $context->extension(AgreementContext::KEY);

        if (! $agreement instanceof AgreementContext) {
            return;
        }

        foreach ($context->inputs as $input) {
            $explanation = (array) ($input->explanation['agreement'] ?? []);
            $type = $explanation['day_type'] ?? null;
            $from = $input->explanation['date_from'] ?? null;

            if (! is_string($type) || ! is_string($from)) {
                continue;
            }

            $day = CarbonImmutable::parse($from);
            $version = $agreement->versionOn($day);

            if ($version !== null && ! in_array($type, DayCalendar::typesOn($version['rules']->dayTypes(), $day), true)) {
                $draft->warnings[] = __(':item gäller :type enligt :agreement, men :date är inte en sådan dag.', [
                    'item' => $input->description,
                    'type' => (string) ($version['rules']->dayTypes()[$type]['name'] ?? $type),
                    'agreement' => $version['rules']->shortName(),
                    'date' => $day->toDateString(),
                ]);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use App\Support\Money\Money;

/**
 * Shows a payroll rule in plain Swedish: a name for the rule and its value
 * as percentages, kronor, days and short lists, never as raw data. Values
 * are stored in basis points ("bp", 100 bp = 1 %) and öre ("ore").
 */
final class RulePresenter
{
    /** @var array<string, string> */
    private const array NAMES = [
        'employer_contribution.full' => 'Arbetsgivaravgift, full',
        'employer_contribution.reduced_by_birthyear' => 'Arbetsgivaravgift för äldre',
        'employer_contribution.youth_reduction' => 'Sänkt arbetsgivaravgift för unga',
        'employer_contribution.min_annual_basis' => 'Gräns för arbetsgivaravgift per år',
        'employer_contribution.regional_support' => 'Regionalt stöd',
        'special_payroll_tax.pension' => 'Särskild löneskatt på pension',
        'sink.rate' => 'SINK, skatt för utomlands bosatta',
        'a_sink.rate' => 'A-SINK, skatt för artister m.fl.',
        'prisbasbelopp' => 'Prisbasbelopp',
        'inkomstbasbelopp' => 'Inkomstbasbelopp',
        'benefit.meal.free_day' => 'Kostförmån, hel dag',
        'benefit.meal.breakfast' => 'Kostförmån, frukost',
        'benefit.meal.lunch_or_dinner' => 'Kostförmån, lunch eller middag',
        'benefit.car.formula' => 'Bilförmån, beräkning',
        'per_diem.domestic.full' => 'Traktamente i Sverige, hel dag',
        'per_diem.domestic.half' => 'Traktamente i Sverige, halv dag',
        'per_diem.domestic.night' => 'Nattraktamente',
        'per_diem.domestic.meal_reductions' => 'Måltidsavdrag på traktamente',
        'per_diem.foreign.by_country' => 'Utlandstraktamente per land',
        'mileage.own_car_tax_free_per_mil' => 'Milersättning, egen bil',
        'mileage.company_car_fossil' => 'Milersättning, förmånsbil (bensin, diesel)',
        'mileage.company_car_electric' => 'Milersättning, förmånsbil (el)',
        'sick_pay.percentage' => 'Sjuklön',
        'sick_pay.karensavdrag_percent_of_weekly' => 'Karensavdrag',
        'sick_pay.employer_period_days' => 'Arbetsgivarens sjuklöneperiod',
        'sick_pay.reinfection_window_days' => 'Återinsjuknande',
        'sick_pay.certificate_from_day' => 'Läkarintyg',
        'vacation.minimum_days' => 'Semesterdagar per år',
        'vacation.supplement_sammaloneregeln' => 'Semestertillägg (sammalöneregeln)',
        'vacation.percent_rule' => 'Semesterlön (procentregeln)',
        'vacation.max_saved_days' => 'Spara semester',
        'vacation.save_years' => 'Sparad semester, tid',
        'gift.tax_free_limits' => 'Skattefria gåvor',
        'wellness.tax_free_limit' => 'Friskvårdsbidrag',
        'agi.field_codes' => 'Fältkoder i arbetsgivardeklarationen',
        'agi.deadlines' => 'Datum för arbetsgivardeklarationen',
        'agi.schema_version' => 'Filformat för arbetsgivardeklarationen',
        'tax.rounding' => 'Avrundning av skatt',
        'tax.one_off' => 'Skatt på engångsbelopp',
        'tax.one_off_without_regular_salary' => 'Skatt på engångsbelopp utan fast lön',
    ];

    /**
     * How a key inside a value reads; ":v" is the formatted value. A key
     * whose name ends in _bp is a percentage, in _ore an amount; under a
     * parent ending in _bp or _ore the children inherit its unit.
     *
     * @var array<string, string>
     */
    private const array PHRASES = [
        'sjukforsakringsavgift' => 'varav sjukförsäkringsavgift :v', 'foraldraforsakringsavgift' => 'varav föräldraförsäkringsavgift :v',
        'alderspensionsavgift' => 'varav ålderspensionsavgift :v', 'efterlevandepensionsavgift' => 'varav efterlevandepensionsavgift :v',
        'arbetsmarknadsavgift' => 'varav arbetsmarknadsavgift :v', 'arbetsskadeavgift' => 'varav arbetsskadeavgift :v', 'allman_loneavgift' => 'varav allmän löneavgift :v',
        'monthly_cap' => 'högst :v per månad', 'deduction' => 'avdrag :v', 'floor' => 'avgiften blir lägst :v', 'not_from_age' => 'inte från :v år',
        'seafarers' => 'sjöinkomst :v', 'after_three_months' => 'efter tre månader :v', 'after_two_years' => 'efter två år :v',
        'max_occasions_per_12_months' => 'högst :v gånger på 12 månader', 'calendar_days_after_report' => 'från den :v:e kalenderdagen efter sjukanmälan',
        'of_monthly_salary_per_day' => ':v av månadslönen per semesterdag', 'weekly' => ':v av veckolönen per semesterdag', 'variable_pay' => ':v på rörlig lön',
        'days_that_must_be_taken' => 'dagar över :v får sparas', 'christmas' => 'julgåva :v', 'anniversary' => 'jubileumsgåva :v', 'memorial' => 'minnesgåva :v',
        'including_vat' => 'inklusive moms', 'prisbasbelopp_share' => ':v av prisbasbeloppet', 'state_rate_share' => ':v av statslåneräntan',
        'plus' => 'plus :v', 'min' => 'lägst :v', 'state_rate' => 'statslåneränta :v', 'price_share' => ':v av nybilspriset', 'reduction_max' => 'nedsättningen högst :v av nybilspriset',
        'long_service_mil' => 'vid minst :v mil i tjänsten', 'long_service_share' => 'värdet blir :v', 'electric' => 'nedsättning för elbil :v', 'hydrogen' => 'nedsättning för vätgasbil :v',
        'plug_in_hybrid' => 'nedsättning för laddhybrid :v', 'gas' => 'nedsättning för gasbil :v', 'full_day_300' => '', 'interest' => '',
        'breakfast_lunch_dinner' => 'frukost, lunch och middag :v', 'lunch_and_dinner' => 'lunch och middag :v', 'lunch_or_dinner' => 'lunch eller middag :v', 'breakfast' => 'frukost :v',
        'components' => '', 'reductions' => '',
    ];

    /** @var array<string, string> */
    private const array AGI_FIELDS = [
        'tax' => 'skatteavdrag', 'cash_salary' => 'kontant bruttolön', 'other_benefits' => 'övriga förmåner', 'car_benefit' => 'bilförmån', 'fuel_benefit' => 'drivmedel vid bilförmån',
        'expense_allowance_other' => 'övriga kostnadsersättningar', 'mileage_flag' => 'bilersättning', 'per_diem_flag' => 'traktamente', 'occupational_pension' => 'tjänstepension',
        'no_work_tax_credit' => 'ersättning utan jobbskatteavdrag', 'sink_tax' => 'skatt SINK', 'a_sink_tax' => 'skatt A-SINK', 'activity_type' => 'verksamhetens art',
        'personal_number' => 'personnummer', 'specification_number' => 'specifikationsnummer', 'workplace_number' => 'arbetsställenummer', 'rut' => 'underlag rut', 'rot' => 'underlag rot',
        'cash_no_contributions' => 'kontant ersättning utan arbetsgivaravgifter',
    ];

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function say(string $text, array $replace = []): string
    {
        $translated = __($text, $replace);

        return is_string($translated) ? $translated : $text;
    }

    public static function name(string $key): string
    {
        return self::say(self::NAMES[$key] ?? $key);
    }

    /**
     * The value as short lines of plain Swedish.
     *
     * @return list<string>
     */
    public static function lines(string $key, mixed $value): array
    {
        $value = (array) $value;

        return array_values(match ($key) {
            'employer_contribution.reduced_by_birthyear' => array_map(fn (mixed $band): string => self::band((array) $band), (array) ($value['bands'] ?? [])),
            'employer_contribution.youth_reduction' => ($value['applies'] ?? false)
                ? [self::say('Födda :from–:to: :rate på lön upp till :cap per månad', ['from' => $value['born_from'], 'to' => $value['born_to'], 'rate' => self::percent((int) $value['bp']), 'cap' => self::kronor((int) $value['monthly_cap_ore'])])]
                : [self::say('Gäller inte under perioden')],
            'tax.rounding' => [($value['mode'] ?? null) === 'DOWN_TO_KRONA' ? self::say('Till hela kronor, ören faller bort') : (string) ($value['mode'] ?? '')],
            'tax.one_off' => self::oneOff($value),
            'agi.deadlines' => [
                self::say('Omsättning upp till 40 miljoner kr: :dates', ['dates' => implode(', ', array_map(fn (mixed $date): string => (string) $date, (array) ($value['turnover_up_to_40_msek'] ?? [])))]),
                self::say('Omsättning över 40 miljoner kr: den :day:e i månaden', ['day' => $value['turnover_over_40_msek_declare_day'] ?? '']),
            ],
            'agi.field_codes' => array_map(fn (mixed $code, int|string $name): string => "{$code} ".self::say(self::AGI_FIELDS[$name] ?? (string) $name), array_values($value), array_keys($value)),
            'agi.schema_version' => [self::say('XML-schema version :version', ['version' => $value['version'] ?? ''])],
            default => self::generic($value),
        });
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private static function generic(array $value, ?string $unit = null): array
    {
        $lines = [];

        foreach ($value as $name => $item) {
            $name = (string) $name;
            $itemUnit = match (true) {
                $name === 'bp' || str_ends_with($name, '_bp') || str_starts_with($name, 'bp_') => 'bp',
                $name === 'ore' || str_ends_with($name, '_ore') => 'ore',
                default => $unit,
            };
            $base = (string) preg_replace('/^bp_|_(bp|ore)$/', '', $name);

            if (is_array($item)) {
                array_push($lines, ...self::generic($item, $itemUnit));

                continue;
            }

            $text = match (true) {
                is_bool($item) => '',
                $itemUnit === 'bp' => self::percent((int) $item),
                $itemUnit === 'ore' => self::kronor((int) $item),
                $name === 'days' => self::say(':count dagar', ['count' => $item]),
                $name === 'years' => self::say(':count år', ['count' => $item]),
                default => number_format((int) $item, 0, ',', ' '),
            };

            if (is_bool($item) && ! $item) {
                continue;
            }

            $phrase = self::PHRASES[$base] ?? ':v';
            $lines[] = trim(str_replace(':v', $text, self::say($phrase === '' ? ':v' : $phrase)));
        }

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    /**
     * @param  array<array-key, mixed>  $band
     */
    private static function band(array $band): string
    {
        $born = isset($band['born_from'])
            ? self::say('Födda :from–:to', ['from' => $band['born_from'], 'to' => $band['born_to']])
            : self::say('Födda :to eller tidigare', ['to' => $band['born_to']]);

        return "{$born}: ".self::percent((int) $band['bp']);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private static function oneOff(array $value): array
    {
        $lines = [];

        foreach ((array) ($value['columns'] ?? []) as $column => $brackets) {
            $parts = array_map(fn (mixed $bracket): string => sprintf('%s–%s kr: %s',
                number_format((int) $bracket[0], 0, ',', ' '),
                $bracket[1] === null ? '' : number_format((int) $bracket[1], 0, ',', ' '),
                self::percent((int) $bracket[2])), (array) $brackets);
            $lines[] = self::say('Kolumn :column: ', ['column' => $column]).implode(' · ', $parts);
        }

        return $lines;
    }

    private static function percent(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, ',', ' '), '0'), ',').' %';
    }

    private static function kronor(int $ore): string
    {
        return $ore % 100 === 0
            ? number_format(intdiv($ore, 100), 0, ',', ' ').' kr'
            : Money::ofOre($ore)->format();
    }
}

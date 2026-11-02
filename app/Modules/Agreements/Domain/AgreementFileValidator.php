<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

use App\Modules\Agreements\Models\AgreementRuleVersion;

/**
 * Checks an agreement data file against nonox.agreement/1 before it is
 * imported (docs/avtal/datamodell.md §4): the shape, that every value has a
 * source that points at a listed document, that rate and wage steps lie
 * inside the version's validity, and that day types and codes refer to
 * things that exist. Returns problems in Swedish; none means valid.
 */
final class AgreementFileValidator
{
    public const string SCHEMA = 'nonox.agreement/1';

    public const array COLLECTIVES = ['ARBETARE', 'TJANSTEMAN'];

    public const array KINDS = ['OB', 'OVERTIME', 'SUPPLEMENT', 'COMMISSION', 'MEAL'];

    /** The engine's pay codes an agreement code may follow (tax, contributions, vacation basis). */
    public const array PAY_CODES = ['OBTILLAGG', 'OVERTID', 'AVTALSTILLAGG', 'PROVISION', 'FORMAN_EGENBETALNING'];

    public const array RATE_TYPES = ['ORE_PER_HOUR', 'PERCENT_OF_HOURLY', 'DIVISOR_OF_MONTHLY', 'ORE', 'PERCENT_OF_TURNOVER'];

    /** Supplements are paid per hour or per occasion (datamodell §6.4). */
    public const array SUPPLEMENT_UNITS = ['HOUR', 'OCCASION'];

    /** The engine's meal benefit codes a meal deduction can reduce (datamodell §6.5). */
    public const array MEAL_BENEFITS = ['KOSTFORMAN_HEL', 'KOSTFORMAN_LUNCH', 'KOSTFORMAN_FRUKOST'];

    public const array VACATION_RULES = ['SAMMALONEREGELN', 'PROCENTREGELN'];

    public const array EXTRA_DAY_CONDITIONS = ['OVERTIME_INCLUDED_IN_SALARY'];

    /** Payroll rule keys an agreement may point at for pension and insurances (datamodell §6.2–6.3). */
    public const array PENSION_RULES = ['pension.saf_lo', 'pension.itp1'];

    public const array INSURANCE_RULES = ['insurance.fora.arbetare', 'insurance.fora.tjansteman'];

    public const array AGE_REFERENCES = ['PERIOD_END', 'MONTH_OF_BIRTHDAY', 'MONTH_AFTER_BIRTHDAY'];

    /** @var list<string> */
    private array $problems = [];

    /** @var list<string> */
    private array $sourceIds = [];

    /**
     * @param  array<string, mixed>  $file  the decoded file, sources included
     * @return list<string>
     */
    public function validate(array $file, ?string $expectedKey = null): array
    {
        $this->problems = [];
        $this->sourceIds = [];

        if (($file['schema'] ?? null) !== self::SCHEMA) {
            return [__('Filen är inte i formatet :schema.', ['schema' => self::SCHEMA])];
        }

        foreach ((array) ($file['sources'] ?? []) as $source) {
            $source = (array) $source;

            if (! is_string($source['id'] ?? null) || preg_match('/^[A-Z0-9]+$/', $source['id']) !== 1 || ! filled($source['title'] ?? null) || ! filled($source['url'] ?? null)) {
                $this->problems[] = __('Varje källa behöver id (versaler och siffror), titel och adress.');

                continue;
            }

            $this->sourceIds[] = $source['id'];
        }

        if ($this->sourceIds === []) {
            $this->problems[] = __('Filen saknar källor.');
        }

        $agreement = (array) ($file['agreement'] ?? []);
        $key = (string) ($agreement['key'] ?? '');

        if (preg_match('/^[a-z0-9-]{3,60}$/', $key) !== 1) {
            $this->problems[] = __('Avtalets nyckel saknas eller har fel form.');
        }

        if ($expectedKey !== null && $key !== $expectedKey) {
            $this->problems[] = __('Avtalets nyckel :key stämmer inte med mappen :folder.', ['key' => $key, 'folder' => $expectedKey]);
        }

        if (! in_array($agreement['variant'] ?? null, AgreementRuleVersion::VARIANTS, true)) {
            $this->problems[] = __('Varianten måste vara MEMBER eller HANGAVTAL.');
        }

        if (! in_array($agreement['collective'] ?? null, self::COLLECTIVES, true)) {
            $this->problems[] = __('Kollektivet måste vara ARBETARE eller TJANSTEMAN.');
        }

        $this->source($agreement, 'agreement');

        $from = (string) ($file['valid_from'] ?? '');
        $to = $file['valid_to'] ?? null;

        if (! self::isDate($from) || ($to !== null && (! is_string($to) || ! self::isDate($to) || $to < $from))) {
            $this->problems[] = __('Giltigheten (valid_from, valid_to) saknas eller är fel.');

            return $this->problems;
        }

        $this->workingTime((array) ($file['working_time'] ?? []));
        $groups = $this->groups((array) ($file['groups'] ?? []));
        $this->minimumWages((array) ($file['minimum_wages'] ?? []), $groups, $from, $to);
        $dayTypes = $this->dayTypes((array) ($file['day_types'] ?? []));
        $codes = $this->codes((array) ($file['codes'] ?? []), $dayTypes, $from, $to);

        foreach ((array) ($file['ob_windows'] ?? []) as $index => $window) {
            $window = (array) $window;
            $this->source($window, "ob_windows.{$index}");

            if (! in_array($window['code'] ?? null, $codes, true)) {
                $this->problems[] = __('OB-fönster :index pekar på en kod som inte finns.', ['index' => $index]);
            }

            if (isset($window['day_type']) && ! in_array($window['day_type'], $dayTypes, true)) {
                $this->problems[] = __('OB-fönster :index pekar på en dagtyp som inte finns.', ['index' => $index]);
            }
        }

        $this->vacation((array) ($file['vacation'] ?? []), $from, $to);
        $this->ruleReference((array) ($file['pension'] ?? []), 'pension', self::PENSION_RULES);
        $this->ruleReference((array) ($file['insurances'] ?? []), 'insurances', self::INSURANCE_RULES);

        if (isset($file['insurances']['admin_fee_bp']) && (! is_int($file['insurances']['admin_fee_bp']) || $file['insurances']['admin_fee_bp'] <= 0)) {
            $this->problems[] = __('insurances.admin_fee_bp måste vara ett positivt heltal i baspunkter.');
        }

        return array_values(array_unique($this->problems));
    }

    /** @param  array<string, mixed>  $workingTime */
    private function workingTime(array $workingTime): void
    {
        if ($workingTime === []) {
            return;
        }

        $this->source($workingTime, 'working_time');

        if (isset($workingTime['hourly_divisor'])) {
            $divisor = (array) $workingTime['hourly_divisor'];
            $this->source($divisor, 'working_time.hourly_divisor');

            if (! self::isPositiveDecimal($divisor['monthly_to_hourly'] ?? null)) {
                $this->problems[] = __('Delaren för timlön måste vara ett positivt tal skrivet som text.');
            }
        }
    }

    /**
     * @param  array<int, mixed>  $groups
     * @return list<string>
     */
    private function groups(array $groups): array
    {
        $codes = [];

        foreach ($groups as $index => $group) {
            $group = (array) $group;
            $this->source($group, "groups.{$index}");

            if (! is_string($group['code'] ?? null) || preg_match('/^[A-Z0-9_]{1,20}$/', $group['code']) !== 1 || ! filled($group['name'] ?? null)) {
                $this->problems[] = __('Lönegrupp :index saknar kod eller namn.', ['index' => $index]);

                continue;
            }

            $codes[] = $group['code'];
        }

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $wages
     * @param  list<string>  $groups
     */
    private function minimumWages(array $wages, array $groups, string $from, ?string $to): void
    {
        if ($wages === []) {
            return;
        }

        $this->source($wages, 'minimum_wages');

        if (! in_array($wages['age_reference'] ?? null, self::AGE_REFERENCES, true)) {
            $this->problems[] = __('Minimilönerna saknar age_reference.');
        }

        foreach ((array) ($wages['steps'] ?? []) as $index => $step) {
            $step = (array) $step;
            $this->insideValidity((string) ($step['valid_from'] ?? ''), $from, $to, "minimum_wages.steps.{$index}");

            foreach ((array) ($step['rows'] ?? []) as $rowIndex => $row) {
                $row = (array) $row;
                $where = "minimum_wages.steps.{$index}.rows.{$rowIndex}";
                $this->source($row, $where);

                if (! in_array($row['group'] ?? null, $groups, true)) {
                    $this->problems[] = __(':where pekar på en lönegrupp som inte finns.', ['where' => $where]);
                }

                $amounts = array_filter([$row['monthly_ore'] ?? null, $row['hourly_ore'] ?? null], fn (mixed $value): bool => $value !== null);

                if ($amounts === [] || array_filter($amounts, fn (mixed $value): bool => ! is_int($value) || $value <= 0) !== []) {
                    $this->problems[] = __(':where behöver monthly_ore eller hourly_ore i hela öre.', ['where' => $where]);
                }

                foreach (['age_min', 'age_max', 'experience_months_min', 'tenure_months_min'] as $field) {
                    if (isset($row[$field]) && (! is_int($row[$field]) || $row[$field] < 0)) {
                        $this->problems[] = __(':where: :field måste vara ett heltal.', ['where' => $where, 'field' => $field]);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $dayTypes
     * @return list<string>
     */
    private function dayTypes(array $dayTypes): array
    {
        $known = [...array_keys(DayCalendar::NAMED_DAYS), ...array_keys(DayCalendar::GENERIC_DAYS)];

        foreach ($dayTypes as $type => $definition) {
            $definition = (array) $definition;
            $this->source($definition, "day_types.{$type}");

            foreach ((array) ($definition['days'] ?? []) as $entry) {
                $entry = (array) $entry;

                if (! in_array($entry['day'] ?? null, $known, true)) {
                    $this->problems[] = __('Dagtypen :type har en dag som kalendern inte känner: :day.', ['type' => $type, 'day' => (string) ($entry['day'] ?? '')]);
                }

                if (array_diff((array) ($entry['weekdays'] ?? []), DayCalendar::WEEKDAYS) !== []) {
                    $this->problems[] = __('Dagtypen :type har en okänd veckodag.', ['type' => $type]);
                }

                foreach (['from', 'to', 'to_next_day'] as $field) {
                    if (isset($entry[$field]) && preg_match('/^([01]\d|2[0-4]):[0-5]\d$/', (string) $entry[$field]) !== 1) {
                        $this->problems[] = __('Dagtypen :type har ett klockslag i fel form.', ['type' => $type]);
                    }
                }

                if (isset($entry['until']) && $entry['until'] !== 'NEXT_WEEKDAY') {
                    $this->problems[] = __('Dagtypen :type: until kan bara vara NEXT_WEEKDAY.', ['type' => $type]);
                }
            }
        }

        return array_map('strval', array_keys($dayTypes));
    }

    /**
     * @param  array<string, mixed>  $codes
     * @param  list<string>  $dayTypes
     * @return list<string>
     */
    private function codes(array $codes, array $dayTypes, string $from, ?string $to): array
    {
        foreach ($codes as $code => $definition) {
            $definition = (array) $definition;
            $code = (string) $code;
            $this->source($definition, "codes.{$code}");

            if (preg_match('/^[A-Z0-9_]{1,20}$/', $code) !== 1) {
                $this->problems[] = __('Koden :code har fel form (versaler, siffror och understreck, högst 20 tecken).', ['code' => $code]);
            }

            if (! in_array($definition['kind'] ?? null, self::KINDS, true) || ! in_array($definition['pay_code'] ?? null, self::PAY_CODES, true) || ! filled($definition['name'] ?? null)) {
                $this->problems[] = __('Koden :code saknar namn, sort (OB eller OVERTIME) eller löneart (OBTILLAGG eller OVERTID).', ['code' => $code]);
            }

            if (isset($definition['day_type']) && ! in_array($definition['day_type'], $dayTypes, true)) {
                $this->problems[] = __('Koden :code pekar på en dagtyp som inte finns.', ['code' => $code]);
            }

            $this->kindFields($code, $definition);

            $rates = (array) ($definition['rates'] ?? []);

            if ($rates === []) {
                $this->problems[] = __('Koden :code saknar sats.', ['code' => $code]);
            }

            foreach ($rates as $index => $step) {
                $step = (array) $step;
                $this->insideValidity((string) ($step['valid_from'] ?? ''), $from, $to, "codes.{$code}.rates.{$index}");
                $this->rate((array) ($step['rate'] ?? []), $code, (string) ($definition['kind'] ?? ''), (string) ($definition['unit'] ?? 'HOUR'));
            }
        }

        return array_map('strval', array_keys($codes));
    }

    /** @param  array<string, mixed>  $rate */
    private function rate(array $rate, string $code, string $kind, string $unit): void
    {
        if (self::rateProblem($rate, $kind, $unit) !== null) {
            $this->problems[] = __('Koden :code har en sats i fel form.', ['code' => $code]);
        }
    }

    /**
     * Why a rate has the wrong form for a kind of code, or null when it is
     * right: a commission is a percentage of the turnover, a meal deduction
     * and a supplement per occasion an amount, the others a rate per hour.
     *
     * @param  array<string, mixed>  $rate
     */
    public static function rateProblem(array $rate, string $kind, string $unit = 'HOUR'): ?string
    {
        $type = $rate['type'] ?? null;
        $allowed = match (true) {
            $kind === 'COMMISSION' => ['PERCENT_OF_TURNOVER'],
            $kind === 'MEAL', $kind === 'SUPPLEMENT' && $unit === 'OCCASION' => ['ORE'],
            default => ['ORE_PER_HOUR', 'PERCENT_OF_HOURLY', 'DIVISOR_OF_MONTHLY'],
        };

        $valid = in_array($type, $allowed, true) && match ($type) {
            'ORE_PER_HOUR', 'ORE' => is_int($rate['ore'] ?? null) && $rate['ore'] > 0,
            'PERCENT_OF_HOURLY', 'PERCENT_OF_TURNOVER' => is_int($rate['bp'] ?? null) && $rate['bp'] > 0,
            default => self::isPositiveDecimal($rate['divisor'] ?? null),
        };

        return $valid ? null : 'rate';
    }

    /** @param  array<string, mixed>  $definition */
    private function kindFields(string $code, array $definition): void
    {
        $kind = $definition['kind'] ?? null;

        if ($kind === 'SUPPLEMENT' && ! in_array($definition['unit'] ?? 'HOUR', self::SUPPLEMENT_UNITS, true)) {
            $this->problems[] = __('Tillägget :code har en okänd enhet (HOUR eller OCCASION).', ['code' => $code]);
        }

        if ($kind === 'COMMISSION' && ! in_array($definition['guarantee'] ?? null, LocalOverrides::GUARANTEES, true)) {
            $this->problems[] = __('Provisionen :code saknar garanti (SALARY eller NONE).', ['code' => $code]);
        }

        if ($kind === 'MEAL' && ! in_array($definition['benefit_code'] ?? null, self::MEAL_BENEFITS, true)) {
            $this->problems[] = __('Kostavdraget :code saknar förmånens löneart (benefit_code).', ['code' => $code]);
        }

        if (isset($definition['vacation_basis']) && ! is_bool($definition['vacation_basis'])) {
            $this->problems[] = __('Koden :code: vacation_basis måste vara true eller false.', ['code' => $code]);
        }
    }

    /** @param  array<string, mixed>  $vacation */
    private function vacation(array $vacation, string $from, ?string $to): void
    {
        if ($vacation === []) {
            return;
        }

        $this->source($vacation, 'vacation');

        if (isset($vacation['rule_monthly']) && ! in_array($vacation['rule_monthly'], self::VACATION_RULES, true)) {
            $this->problems[] = __('vacation.rule_monthly måste vara SAMMALONEREGELN eller PROCENTREGELN.');
        }

        foreach (['percent_bp', 'supplement_bp_per_day', 'variable_pay_percent_bp', 'saved_day_percent_bp'] as $field) {
            if (isset($vacation[$field]) && (! is_int($vacation[$field]) || $vacation[$field] <= 0 || $vacation[$field] >= 10_000)) {
                $this->problems[] = __('vacation.:field måste vara baspunkter mellan 1 och 9999.', ['field' => $field]);
            }
        }

        if (isset($vacation['minimum_daily'])) {
            $minimum = (array) $vacation['minimum_daily'];
            $this->source($minimum, 'vacation.minimum_daily');

            if (! in_array($minimum['part_time'] ?? null, ['PRO_RATA', 'FULL_TIME_ONLY'], true)) {
                $this->problems[] = __('vacation.minimum_daily.part_time måste vara PRO_RATA eller FULL_TIME_ONLY.');
            }

            foreach ((array) ($minimum['steps'] ?? []) as $index => $step) {
                $step = (array) $step;
                $this->insideValidity((string) ($step['valid_from'] ?? ''), $from, $to, "vacation.minimum_daily.steps.{$index}");

                foreach ((array) ($step['rows'] ?? []) as $row) {
                    $row = (array) $row;

                    if (! is_int($row['ore'] ?? null) || $row['ore'] <= 0) {
                        $this->problems[] = __('vacation.minimum_daily.steps.:index behöver ore i hela öre.', ['index' => $index]);
                    }
                }
            }
        }

        foreach ((array) ($vacation['extra_days'] ?? []) as $index => $extra) {
            $extra = (array) $extra;
            $this->source($extra, "vacation.extra_days.{$index}");

            if (! in_array($extra['when'] ?? null, self::EXTRA_DAY_CONDITIONS, true) || ! is_int($extra['days'] ?? null) || $extra['days'] <= 0) {
                $this->problems[] = __('vacation.extra_days.:index har fel form.', ['index' => $index]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  list<string>  $keys
     */
    private function ruleReference(array $section, string $where, array $keys): void
    {
        if ($section === []) {
            return;
        }

        $this->source($section, $where);

        if (! in_array($section['rule_key'] ?? null, $keys, true)) {
            $this->problems[] = __(':where.rule_key måste vara en av :keys.', ['where' => $where, 'keys' => implode(', ', $keys)]);
        }
    }

    private function insideValidity(string $date, string $from, ?string $to, string $where): void
    {
        if (! self::isDate($date) || $date < $from || ($to !== null && $date > $to)) {
            $this->problems[] = __(':where: datumet ligger utanför versionens giltighet.', ['where' => $where]);
        }
    }

    /** @param  array<string, mixed>  $object */
    private function source(array $object, string $where): void
    {
        $source = $object['source'] ?? null;

        if (! is_string($source) || trim($source) === '') {
            $this->problems[] = __(':where saknar källa.', ['where' => $where]);

            return;
        }

        foreach (array_map('trim', explode(';', $source)) as $index => $part) {
            $id = (string) strtok($part, '#');
            $hasId = str_contains($part, '#') || preg_match('/^[A-Z0-9]+$/', $part) === 1;

            if (($index === 0 || $hasId) && ! in_array($id, $this->sourceIds, true)) {
                $this->problems[] = __(':where hänvisar till en källa som inte finns i listan: :source.', ['where' => $where, 'source' => $part]);
            }
        }
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    private static function isPositiveDecimal(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d+(\.\d+)?$/', $value) === 1 && (float) $value > 0;
    }
}

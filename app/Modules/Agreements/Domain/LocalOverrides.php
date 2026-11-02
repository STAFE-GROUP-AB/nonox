<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

/**
 * What a company's local agreement (lokalt avtal) may change in the central
 * agreement data (ADR-033 §3 and §6, datamodell §6.6), and how. A local
 * override is a fact about the company, read from its own document and
 * approved by a person; the central values stay as they are. Overrides
 * marked APPLIED change the calculation once a person with
 * agreements:verify has verified them; RECORDED ones are kept and shown
 * but not calculated by NONOX yet.
 */
final class LocalOverrides
{
    public const string SOURCE_ID = 'LOKALT';

    public const string APPLIED = 'APPLIED';

    public const string RECORDED = 'RECORDED';

    /** Path pattern ("*" = an agreement code) => how it is used. */
    public const array PATHS = [
        'codes.*.rate' => self::APPLIED,
        'codes.*.guarantee' => self::APPLIED,
        'vacation.percent_bp' => self::APPLIED,
        'vacation.supplement_bp_per_day' => self::APPLIED,
        'working_time.calculation_period_weeks' => self::RECORDED,
        'overtime.comp_time_hours_per_hour' => self::RECORDED,
        'other' => self::RECORDED,
    ];

    public const array GUARANTEES = ['SALARY', 'NONE'];

    public static function pattern(string $path): ?string
    {
        foreach (array_keys(self::PATHS) as $pattern) {
            if (preg_match('/^'.str_replace(['\.', '\*'], ['\.', '[A-Z0-9_]{1,20}'], preg_quote($pattern, '/')).'$/', $path) === 1) {
                return $pattern;
            }
        }

        return null;
    }

    public static function allowed(string $path): bool
    {
        return self::pattern($path) !== null;
    }

    public static function applied(string $path): bool
    {
        $pattern = self::pattern($path);

        return $pattern !== null && self::PATHS[$pattern] === self::APPLIED;
    }

    /**
     * Why a proposed override cannot be used, in Swedish, or null when it
     * can: the path must be allowed, a code must exist in the agreement and
     * the value must have the right form.
     */
    public static function problem(string $path, mixed $value, AgreementRules $rules): ?string
    {
        $pattern = self::pattern($path);

        if ($pattern === null) {
            return self::say(__('Avvikelsen gäller något som ett lokalt avtal inte kan ändra i NONOX.'));
        }

        if (str_starts_with($pattern, 'codes.')) {
            $code = explode('.', $path)[1];
            $definition = $rules->code($code);

            if ($definition === null) {
                return self::say(__('Koden :code finns inte i avtalet.', ['code' => $code]));
            }

            if ($pattern === 'codes.*.guarantee') {
                return ($definition['kind'] ?? null) === 'COMMISSION' && in_array($value, self::GUARANTEES, true) ? null : self::say(__('Garantin kan bara anges för provision, som SALARY eller NONE.'));
            }

            return AgreementFileValidator::rateProblem(is_array($value) ? $value : [], (string) ($definition['kind'] ?? ''), (string) ($definition['unit'] ?? 'HOUR')) === null
                ? null
                : self::say(__('Satsen har fel form.'));
        }

        $problem = match ($pattern) {
            'vacation.percent_bp', 'vacation.supplement_bp_per_day' => is_int($value) && $value > 0 && $value < 10_000 ? null : __('Procentsatsen anges i baspunkter, till exempel 1300 för 13 %.'),
            'working_time.calculation_period_weeks' => is_int($value) && $value > 0 && $value <= 52 ? null : __('Beräkningsperioden anges i hela veckor, högst 52.'),
            'overtime.comp_time_hours_per_hour' => is_string($value) && preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? null : __('Komptiden anges som timmar per övertidstimme, till exempel 1.5.'),
            default => is_string($value) && trim($value) !== '' ? null : __('Beskriv avvikelsen.'),
        };

        return $problem === null ? null : self::say($problem);
    }

    /**
     * The rules with one override laid over them. Only APPLIED paths
     * change anything; the path is listed under local_overrides with its
     * source so explanations can say the value is local.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function set(array $rules, string $path, mixed $value, string $source): array
    {
        if (! self::applied($path)) {
            return $rules;
        }

        $parts = explode('.', $path);

        if ($parts[0] === 'codes') {
            if (! isset($rules['codes'][$parts[1]]) || ! is_array($rules['codes'][$parts[1]])) {
                return $rules;
            }

            if ($parts[2] === 'rate') {
                // One step from the start of time: the local rate applies on every day the version covers.
                $rules['codes'][$parts[1]]['rates'] = [['valid_from' => '1900-01-01', 'rate' => $value]];
            } else {
                $rules['codes'][$parts[1]][$parts[2]] = $value;
            }

            $rules['codes'][$parts[1]]['source'] = $source;
        }

        if ($parts[0] === 'vacation') {
            $rules['vacation'] = [...(array) ($rules['vacation'] ?? []), $parts[1] => $value];
        }

        $rules['local_overrides'] = [...(array) ($rules['local_overrides'] ?? []), $path => $source];

        return $rules;
    }

    /**
     * The override value in nonox.agreement/1 form from what a person or
     * the AI entered: kronor, a percentage, a divisor, weeks, hours or text.
     * The form of a rate follows the kind of code: an amount is per hour,
     * or per occasion or day for a supplement per occasion and a meal
     * deduction; a percentage is of the hourly wage, or of the turnover for
     * a commission. Null when nothing usable was entered.
     *
     * @param  array{amount_kr?: ?string, percent?: ?string, divisor?: ?string, weeks?: ?string, hours?: ?string, text?: ?string}  $entered
     */
    public static function valueFrom(string $path, array $entered, AgreementRules $rules): mixed
    {
        $pattern = self::pattern($path);
        $number = fn (?string $value): ?string => $value !== null && preg_match('/^\d+([.,]\d+)?$/', trim($value)) === 1 ? str_replace(',', '.', trim($value)) : null;
        $basisPoints = fn (?string $value): ?int => ($decimal = $number($value)) === null ? null : (int) round((float) $decimal * 100);
        $amount = $number($entered['amount_kr'] ?? null);
        $percent = $basisPoints($entered['percent'] ?? null);
        $divisor = $number($entered['divisor'] ?? null);
        $text = trim((string) ($entered['text'] ?? ''));

        if ($pattern === 'codes.*.rate') {
            $code = (array) $rules->code(explode('.', $path)[1]);
            $kind = (string) ($code['kind'] ?? '');
            $perOccasion = $kind === 'MEAL' || ($kind === 'SUPPLEMENT' && ($code['unit'] ?? 'HOUR') === 'OCCASION');

            return match (true) {
                $kind === 'COMMISSION' && $percent !== null => ['type' => 'PERCENT_OF_TURNOVER', 'bp' => $percent],
                $amount !== null => ['type' => $perOccasion ? 'ORE' : 'ORE_PER_HOUR', 'ore' => (int) round((float) $amount * 100)],
                $percent !== null => ['type' => 'PERCENT_OF_HOURLY', 'bp' => $percent],
                $divisor !== null => ['type' => 'DIVISOR_OF_MONTHLY', 'divisor' => $divisor],
                default => null,
            };
        }

        return match ($pattern) {
            'codes.*.guarantee' => in_array(mb_strtoupper($text), self::GUARANTEES, true) ? mb_strtoupper($text) : null,
            'vacation.percent_bp', 'vacation.supplement_bp_per_day' => $percent,
            'working_time.calculation_period_weeks' => ctype_digit($weeks = trim((string) ($entered['weeks'] ?? ''))) ? (int) $weeks : null,
            'overtime.comp_time_hours_per_hour' => $number($entered['hours'] ?? null),
            'other' => $text === '' ? null : mb_substr($text, 0, 500),
            default => null,
        };
    }

    /**
     * What a person typed as a value ("30" kronor, "35 %", "/600", "16"
     * weeks, "1,5" hours or text), in the fields valueFrom reads.
     *
     * @return array{amount_kr?: string, percent?: string, divisor?: string, weeks?: string, hours?: string, text?: string}
     */
    public static function parseEntered(string $path, string $typed): array
    {
        $typed = trim(str_replace(["\u{00a0}", ' kr', 'kr'], [' ', '', ''], $typed));
        $pattern = self::pattern($path);

        return match (true) {
            $pattern === 'codes.*.guarantee', $pattern === 'other' => ['text' => $typed],
            $pattern === 'working_time.calculation_period_weeks' => ['weeks' => $typed],
            $pattern === 'overtime.comp_time_hours_per_hour' => ['hours' => $typed],
            str_starts_with($typed, '/') => ['divisor' => trim(substr($typed, 1))],
            str_ends_with($typed, '%') || str_starts_with((string) $pattern, 'vacation.') => ['percent' => trim(rtrim($typed, '%'))],
            default => ['amount_kr' => str_replace(' ', '', $typed)],
        };
    }

    /** The value in plain Swedish, for the review list and the payslip explanation. */
    public static function describe(string $path, mixed $value): string
    {
        $percent = fn (int $bp): string => number_format($bp / 100, 2, ',', ' ').' %';

        if (is_array($value)) {
            return match ($value['type'] ?? null) {
                'ORE_PER_HOUR' => __(':amount per timme', ['amount' => number_format(((int) $value['ore']) / 100, 2, ',', ' ').' kr']),
                'ORE' => __(':amount per tillfälle eller dag', ['amount' => number_format(((int) $value['ore']) / 100, 2, ',', ' ').' kr']),
                'PERCENT_OF_HOURLY' => __(':percent av timlönen', ['percent' => $percent((int) $value['bp'])]),
                'PERCENT_OF_TURNOVER' => __(':percent av omsättningen', ['percent' => $percent((int) $value['bp'])]),
                'DIVISOR_OF_MONTHLY' => __('månadslönen / :divisor per timme', ['divisor' => (string) $value['divisor']]),
                default => '',
            };
        }

        return match (self::pattern($path)) {
            'vacation.percent_bp', 'vacation.supplement_bp_per_day' => $percent((int) $value),
            'working_time.calculation_period_weeks' => __(':weeks veckor', ['weeks' => (int) $value]),
            'overtime.comp_time_hours_per_hour' => __(':hours timmar ledigt per övertidstimme', ['hours' => str_replace('.', ',', (string) $value)]),
            'codes.*.guarantee' => $value === 'SALARY' ? __('garanterad lön') : __('ingen garanti'),
            default => (string) $value,
        };
    }

    /**
     * A translated message as text (the translator can return an array
     * for a key that names a group).
     *
     * @param  array<mixed>|string  $text
     */
    private static function say(array|string $text): string
    {
        return is_string($text) ? $text : '';
    }

    /** The Swedish name of what a path changes, for the review list. */
    public static function label(string $path): string
    {
        $pattern = self::pattern($path);
        $code = str_starts_with($path, 'codes.') ? explode('.', $path)[1] : '';

        return match ($pattern) {
            'codes.*.rate' => __('Sats för :code', ['code' => $code]),
            'codes.*.guarantee' => __('Garanti för :code', ['code' => $code]),
            'vacation.percent_bp' => __('Semesterlön i procent'),
            'vacation.supplement_bp_per_day' => __('Semestertillägg per dag'),
            'working_time.calculation_period_weeks' => __('Beräkningsperiod för arbetstid'),
            'overtime.comp_time_hours_per_hour' => __('Komptid per övertidstimme'),
            'other' => __('Annan avvikelse'),
            default => $path,
        };
    }
}

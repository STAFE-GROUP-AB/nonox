<?php

declare(strict_types=1);

namespace App\Support\Guide;

/**
 * The groups the start plan is shown in (ADR-051): kept in code, keyed by
 * the step's stable key, so the content format of startplan.json does not
 * change. A step that is not listed falls into the last group. Groups keep
 * the order of the plan's first step in each.
 */
final class StepGroups
{
    public const string REGISTRATION = 'registrering';

    public const string MONEY = 'pengar-och-skatt';

    public const string BOOKKEEPING = 'bokforing-i-nonox';

    public const string AGREEMENTS = 'avtal-och-skydd';

    /** Steps written for one or more industries (stage 4), unless listed below. */
    public const string INDUSTRY = 'din-bransch';

    public const string GROWTH = 'att-vaxa';

    public const string OTHER = 'fler-steg';

    /** @var array<string, string> step key => group */
    private const array STEPS = [
        'valj-bolagsform' => self::REGISTRATION,
        'registrera-hos-bolagsverket' => self::REGISTRATION,
        'anmal-verklig-huvudman' => self::REGISTRATION,
        'for-aktiebok' => self::REGISTRATION,
        'skydda-foretagsnamnet' => self::REGISTRATION,
        'registrera-hos-skatteverket' => self::REGISTRATION,
        'ansok-om-f-skatt' => self::REGISTRATION,
        'ta-stallning-till-moms' => self::REGISTRATION,
        'registrera-som-arbetsgivare' => self::REGISTRATION,
        'satt-in-aktiekapitalet' => self::MONEY,
        'oppna-foretagskonto' => self::MONEY,
        'valj-bokforingsmetod' => self::MONEY,
        'satt-undan-moms-och-skatt' => self::MONEY,
        'forsta-momsdeklarationen' => self::MONEY,
        'lagg-upp-foretaget-i-nonox' => self::BOOKKEEPING,
        'valj-momssats-pa-artiklar' => self::BOOKKEEPING,
        'bokfor-inkop-fore-start' => self::BOOKKEEPING,
        'bestam-arkivering' => self::BOOKKEEPING,
        'planera-arsstamma-och-arsredovisning' => self::BOOKKEEPING,
        'kassaregister-och-personalliggare' => self::AGREEMENTS,
        'skriv-avtal-mellan-agarna' => self::AGREEMENTS,
        'se-over-forsakringar' => self::AGREEMENTS,
        'ordna-personuppgifterna' => self::AGREEMENTS,
        'rakna-fram-ditt-pris' => self::GROWTH,
        'satt-betalningsvillkor' => self::GROWTH,
        'gor-en-likviditetsbudget' => self::GROWTH,
        'lagg-upp-saljtratten' => self::GROWTH,
        'forbered-forsta-anstallningen' => self::GROWTH,
    ];

    /** @return array<string, string> group => Swedish heading, in display order */
    public static function labels(): array
    {
        return [
            self::REGISTRATION => __('Registrering'),
            self::MONEY => __('Pengar och skatt'),
            self::BOOKKEEPING => __('Bokföring i NONOX'),
            self::AGREEMENTS => __('Avtal och skydd'),
            self::INDUSTRY => __('Din bransch'),
            self::GROWTH => __('Att växa'),
            self::OTHER => __('Fler steg'),
        ];
    }

    /** @param  list<string>  $industries  the step's industries: an unlisted industry step goes to Din bransch */
    public static function of(string $stepKey, array $industries = []): string
    {
        return self::STEPS[$stepKey] ?? ($industries === [] ? self::OTHER : self::INDUSTRY);
    }

    /**
     * The steps in their groups, in the groups' order, keeping the plan's
     * order inside each group, numbered from 1 in the order they are shown.
     *
     * @param  list<StartPlanStep>  $steps
     * @return list<array{key: string, label: string, steps: list<array{number: int, step: StartPlanStep}>}>
     */
    public static function group(array $steps): array
    {
        $grouped = [];

        foreach ($steps as $step) {
            $grouped[self::of($step->key, $step->industries)][] = $step;
        }

        $groups = [];
        $number = 0;

        foreach (self::labels() as $key => $label) {
            if (! isset($grouped[$key])) {
                continue;
            }

            $groups[] = ['key' => $key, 'label' => $label, 'steps' => array_map(function (StartPlanStep $step) use (&$number): array {
                return ['number' => ++$number, 'step' => $step];
            }, $grouped[$key])];
        }

        return $groups;
    }

    /** The short note for a step that only applies on a condition, for the public path. */
    public static function conditionLabel(?string $condition): ?string
    {
        if ($condition === 'vat_registered') {
            return __('om du är momsregistrerad');
        }

        if ($condition === 'not_vat_registered') {
            return __('om du inte är momsregistrerad');
        }

        if ($condition === 'employer_registered') {
            return __('om du har anställda');
        }

        return null;
    }
}

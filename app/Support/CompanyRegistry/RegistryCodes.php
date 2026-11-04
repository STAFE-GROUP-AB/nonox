<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

use Illuminate\Support\Facades\DB;

/**
 * The code lists of SCB's company register in words (ADR-047), from SCB's
 * variable description (variabelbeskrivning-afr_261009.pdf): legal forms,
 * employee and turnover size classes, counties, Bolagsverket's
 * deregistration reasons and proceedings. Municipalities and the status at
 * Bolagsverket come from the API's code tables (company_registry_codes).
 */
final class RegistryCodes
{
    /** The legal forms a prospect search offers (SCB Jurform); natural persons are not in the register. */
    public const array LEGAL_FORMS = [
        '49' => 'Aktiebolag',
        '31' => 'Handelsbolag, kommanditbolag',
        '51' => 'Ekonomisk förening',
        '53' => 'Bostadsrättsförening',
        '61' => 'Ideell förening',
        '62' => 'Samfällighet',
        '63' => 'Registrerat trossamfund',
        '71' => 'Familjestiftelse',
        '72' => 'Stiftelse eller fond',
        '43' => 'Europabolag',
        '41' => 'Bankaktiebolag',
        '42' => 'Försäkringsaktiebolag',
        '92' => 'Ömsesidigt försäkringsbolag',
        '93' => 'Sparbank',
        '96' => 'Utländsk juridisk person',
        '81' => 'Statlig enhet',
        '82' => 'Kommun',
        '84' => 'Region',
    ];

    /** SCB AnstKl: employees in classes (the exact number is confidential). */
    public const array EMPLOYEE_CLASSES = [
        1 => '0 anställda',
        2 => '1–4 anställda',
        3 => '5–9 anställda',
        4 => '10–19 anställda',
        5 => '20–49 anställda',
        6 => '50–99 anställda',
        7 => '100–199 anställda',
        8 => '200–499 anställda',
        9 => '500–999 anställda',
        10 => '1 000–1 499 anställda',
        11 => '1 500–1 999 anställda',
        12 => '2 000–2 999 anställda',
        13 => '3 000–3 999 anställda',
        14 => '4 000–4 999 anställda',
        15 => '5 000–9 999 anställda',
        16 => 'Minst 10 000 anställda',
    ];

    /** SCB OmsKl: turnover in classes, from the VAT returns. */
    public const array TURNOVER_CLASSES = [
        0 => 'Under 1 tkr',
        1 => '1–499 tkr',
        2 => '500–999 tkr',
        3 => '1–4,9 mkr',
        4 => '5–9,9 mkr',
        5 => '10–19,9 mkr',
        6 => '20–49,9 mkr',
        7 => '50–99,9 mkr',
        8 => '100–499,9 mkr',
        9 => '500–999,9 mkr',
        10 => '1–4,9 mdkr',
        11 => '5–9,9 mdkr',
        12 => 'Minst 10 mdkr',
    ];

    /** The counties (län) with their two-digit codes (Rikets indelningar). */
    public const array COUNTIES = [
        '01' => 'Stockholms län',
        '03' => 'Uppsala län',
        '04' => 'Södermanlands län',
        '05' => 'Östergötlands län',
        '06' => 'Jönköpings län',
        '07' => 'Kronobergs län',
        '08' => 'Kalmar län',
        '09' => 'Gotlands län',
        '10' => 'Blekinge län',
        '12' => 'Skåne län',
        '13' => 'Hallands län',
        '14' => 'Västra Götalands län',
        '17' => 'Värmlands län',
        '18' => 'Örebro län',
        '19' => 'Västmanlands län',
        '20' => 'Dalarnas län',
        '21' => 'Gävleborgs län',
        '22' => 'Västernorrlands län',
        '23' => 'Jämtlands län',
        '24' => 'Västerbottens län',
        '25' => 'Norrbottens län',
    ];

    /** Bolagsverket's ongoing proceedings (pagandeAvvecklingsEllerOmstruktureringsforfarande). */
    public const array PROCEEDINGS = [
        'KK' => 'Konkurs',
        'LI' => 'Likvidation',
        'FR' => 'Företagsrekonstruktion',
    ];

    /** The status at Bolagsverket (SCB BolStat) for the most common codes; the rest from the API's code table. */
    public const array BOLAGSVERKET_STATUS = [
        0 => 'Normalläge',
        20 => 'Konkurs inledd',
        21 => 'Konkurs avslutad',
        32 => 'Likvidation beslutad',
        33 => 'Likvidation fortsätter',
        31 => 'Likvidation avslutad',
        41 => 'Upplöst genom fusion',
        80 => 'Företagsrekonstruktion inledd',
    ];

    public static function legalForm(?string $code): ?string
    {
        return $code === null ? null : (self::LEGAL_FORMS[$code] ?? (string) __('Juridisk form :code', ['code' => $code]));
    }

    public static function employees(?int $class): ?string
    {
        return $class === null ? null : (self::EMPLOYEE_CLASSES[$class] ?? null);
    }

    public static function turnover(?int $class): ?string
    {
        return $class === null ? null : (self::TURNOVER_CLASSES[$class] ?? null);
    }

    public static function county(?string $code): ?string
    {
        return $code === null ? null : (self::COUNTIES[$code] ?? $code);
    }

    public static function proceedings(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::PROCEEDINGS[substr($code, 0, 2)] ?? $code;
    }

    /**
     * Names from the API's code tables, e.g. municipalities.
     *
     * @return array<string, string>
     */
    public static function table(string $kind): array
    {
        /** @var array<string, string> */
        return DB::table('company_registry_codes')->where('kind', $kind)->orderBy('name')->pluck('name', 'code')->all();
    }

    public static function bolagsverketStatus(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::BOLAGSVERKET_STATUS[$code] ?? (DB::table('company_registry_codes')->where('kind', 'bolagsverket_status')->where('code', (string) $code)->value('name') ?: (string) $code);
    }
}

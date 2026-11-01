<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Paxml;

/**
 * PAXml's recommended time codes (tidkod) that a payroll file import never
 * turns into pay, from the code list in the 2.3 schema
 * (https://www.paxml.se/2.3/paxml.xsd, simpleType tidkodTYPE):
 *
 * - Absence (sjukdom, föräldraledighet, VAB, semester, tjänstledighet...)
 *   is registered as absence in the payroll run, where sick pay, vacation
 *   and deductions are calculated, and its cause is sensitive.
 * - Balances and schedule time (schematid, flex, tidssaldon) are not pay.
 *
 * Work, overtime, OB, on-call and supplements are mapped by the company.
 */
final class PaxmlCodes
{
    public const array ABSENCE = [
        'SJK', 'SJK_KAR', 'SJK_LÖN', 'SJK_ERS', 'SJK_PEN', 'ASK', 'HAV', 'FPE', 'VAB', 'SMB', 'UTB', 'MIL', 'SVE', 'NÄR', 'TJL',
        'SEM', 'SEM_BET', 'SEM_SPA', 'SEM_OBE', 'SEM_FÖR', 'KOM', 'PEM', 'PER', 'FAC', 'ATK', 'KON', 'PAP',
        'JS1', 'JS2', 'JS3', 'JS4', 'JS5', 'BS1', 'BS2', 'BS3', 'BS4', 'BS5', 'ATF',
        'FR1', 'FR2', 'FR3', 'FR4', 'FR5', 'FR6', 'FR7', 'FR8', 'FR9',
    ];

    public const array BALANCE = ['SCH', 'FLX', 'FRX', 'NVX', 'TS1', 'TS2', 'TS3', 'TS4', 'TS5', 'TS6', 'TS7', 'TS8', 'TS9'];

    public static function isAbsence(PayrollFileLine $line): bool
    {
        return $line->section === PayrollFileLine::TIME && in_array(mb_strtoupper((string) $line->code), self::ABSENCE, true);
    }

    public static function isBalance(PayrollFileLine $line): bool
    {
        return $line->section === PayrollFileLine::TIME && in_array(mb_strtoupper((string) $line->code), self::BALANCE, true);
    }
}

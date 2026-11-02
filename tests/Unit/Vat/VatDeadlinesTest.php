<?php

declare(strict_types=1);

use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\Vat\VatDeadlines;
use Carbon\CarbonImmutable;

it('gives Skatteverket\'s last day for a VAT period', function (VatPeriod $period, string $periodEnd, bool $large, ?string $expected) {
    expect(VatDeadlines::for($period, CarbonImmutable::parse($periodEnd), $large)?->toDateString())->toBe($expected);
})->with([
    'monthly, small: 12th of the second month' => [VatPeriod::Monthly, '2026-01-31', false, '2026-03-12'],
    'monthly, small: 17th in August' => [VatPeriod::Monthly, '2026-06-30', false, '2026-08-17'],
    'monthly, small: 17th in January, Saturday moves to Monday' => [VatPeriod::Monthly, '2025-11-30', false, '2026-01-19'],
    'monthly, large: 26th of the next month' => [VatPeriod::Monthly, '2026-04-30', true, '2026-05-26'],
    'monthly, large: 27th in December, Sunday moves to Monday' => [VatPeriod::Monthly, '2026-11-30', true, '2026-12-28'],
    'quarterly: 12th of the second month' => [VatPeriod::Quarterly, '2026-03-31', false, '2026-05-12'],
    'quarterly: 17th in August' => [VatPeriod::Quarterly, '2026-06-30', false, '2026-08-17'],
    'yearly without a legal form: not given' => [VatPeriod::Yearly, '2026-12-31', false, null],
]);

/*
 * Yearly VAT, SFL 26 kap. 33, 33 a and 33 b §§ (riksdagen.se, read 2026-11-02)
 * and Skatteverket "När ska jag deklarera moms?" (read 2026-11-02). Dates
 * worked out by hand, then moved past weekends and holidays:
 * 2027-05-12 Wednesday, 2027-07-12 Monday, 2027-08-17 Tuesday,
 * 2026-11-12 Thursday, 2026-12-12 Saturday → 2026-12-14, 2026-12-27 Sunday
 * → 2026-12-28, 2027-01-17 Sunday → 2027-01-18, 2027-03-12 Friday,
 * 2027-04-12 Monday, 2027-02-26 Friday, 2026-08-26 Wednesday.
 * Enligt granskning 2026-11-02, juristgranskning återstår (AUD-MOMS-9, #60).
 */
it('gives the last day for yearly VAT by legal form, EU trade and filing', function (LegalForm $form, string $yearEnd, bool $euTrade, bool $electronic, string $expected) {
    expect(VatDeadlines::for(VatPeriod::Yearly, CarbonImmutable::parse($yearEnd), false, $form, $euTrade, $electronic)?->toDateString())->toBe($expected);
})->with([
    'EF, no EU trade: 12 May the year after (33 a §)' => [LegalForm::Ef, '2026-12-31', false, true, '2027-05-12'],
    'EF with EU trade: 26th of the second month (33 a § andra st., 33 §)' => [LegalForm::Ef, '2026-12-31', true, true, '2027-02-26'],
    'AB, 31 Dec, paper: 12 July (33 b §)' => [LegalForm::Ab, '2026-12-31', false, false, '2027-07-12'],
    'AB, 31 Dec, electronic: 17 August' => [LegalForm::Ab, '2026-12-31', false, true, '2027-08-17'],
    'AB, 30 Apr, paper: 12 November' => [LegalForm::Ab, '2026-04-30', false, false, '2026-11-12'],
    'AB, 30 Apr, electronic: 12 December, Saturday → Monday' => [LegalForm::Ab, '2026-04-30', false, true, '2026-12-14'],
    'ekonomisk förening, 30 Jun, paper: 27 December, Sunday → Monday' => [LegalForm::EkonomiskForening, '2026-06-30', false, false, '2026-12-28'],
    'AB, 30 Jun, electronic: 17 January, Sunday → Monday' => [LegalForm::Ab, '2026-06-30', false, true, '2027-01-18'],
    'AB, 31 Aug, paper: 12 March' => [LegalForm::Ab, '2026-08-31', false, false, '2027-03-12'],
    'AB, 31 Aug, electronic: 12 April' => [LegalForm::Ab, '2026-08-31', false, true, '2027-04-12'],
    'AB, 31 Mar (Skatteverket: January–April): 12 December, Saturday → Monday' => [LegalForm::Ab, '2026-03-31', false, true, '2026-12-14'],
    'AB, 30 Sep (Skatteverket: September–December), paper: 12 July' => [LegalForm::Ab, '2026-09-30', false, false, '2027-07-12'],
    'AB with EU trade, 31 Dec: 26 February (33 b § tredje st.)' => [LegalForm::Ab, '2026-12-31', true, true, '2027-02-26'],
    'AB with EU trade, 30 Jun: 26 August' => [LegalForm::Ab, '2026-06-30', true, true, '2026-08-26'],
    'AB with EU trade, 31 Oct: 27 December, Sunday → Monday' => [LegalForm::Ab, '2026-10-31', true, true, '2026-12-28'],
    'HB: 26th of the second month (33 §)' => [LegalForm::Hb, '2026-12-31', false, true, '2027-02-26'],
    'KB: 26th of the second month (33 §)' => [LegalForm::Kb, '2026-12-31', false, false, '2027-02-26'],
]);

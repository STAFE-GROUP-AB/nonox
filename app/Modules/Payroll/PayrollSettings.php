<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

/**
 * The payroll module's settings per company (PAYROLL_MODULE.md B.3), with
 * the allowed values and defaults. Stored in company_modules.settings.
 */
final class PayrollSettings
{
    /** @var array<string, list<string>|null> setting => allowed values, null for numbers and booleans */
    public const array OPTIONS = [
        'salary_period' => ['MONTHLY_CURRENT', 'MONTHLY_ARREARS'],
        'default_payment_day' => null,
        'proration_method' => ['WORK_SCHEDULE', 'CALENDAR_DAYS'],
        'vacation_year_start_month' => null,
        'vacation_rule_default' => ['SAMMALONEREGELN', 'PROCENTREGELN'],
        'vacation_liability_booking' => ['MONTHLY', 'YEAR_END'],
        'vacation_day_value_percent' => null,
        'vacation_day_method' => ['NET', 'GROSS'],
        'vacation_payout_tax' => ['TABLE', 'ONE_OFF'],
        'net_pay_rounding' => ['ORE', 'WHOLE_KRONOR'],
        'segregation_of_duties' => null,
        'use_employment_dimensions' => null,
        'payment_method' => ['PAIN001', 'BANKGIROT_SALARY', 'MANUAL'],
        'payslip_delivery' => ['PORTAL', 'PORTAL_AND_EMAIL_NOTICE'],
        'employee_self_service' => null,
        'agi_submission_mode' => ['FILE', 'API'],
        'agi_deadline_group' => ['TURNOVER_UP_TO_40_MSEK', 'TURNOVER_OVER_40_MSEK'],
        'holiday_calendar' => ['SE'],
        'regional_support' => null,
    ];

    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        'salary_period' => 'MONTHLY_CURRENT',
        'default_payment_day' => 25,
        'proration_method' => 'WORK_SCHEDULE',
        'absence_deduction_method' => 'DAY_AND_HOUR',
        'vacation_year_start_month' => 4,
        'vacation_rule_default' => 'SAMMALONEREGELN',
        'vacation_liability_booking' => 'MONTHLY',
        // The value of one vacation day under sammalöneregeln for the liability; semesterlagen sets none, 4.6 % is common in collective agreements.
        'vacation_day_value_percent' => '4.6',
        // Part-time on fewer than five days a week: count scheduled days and scale the days earned (begränsad nettometod).
        'vacation_day_method' => 'NET',
        // Semesterersättning paid with every salary is regular pay, so taxed by the table; ONE_OFF taxes it as an engångsbelopp.
        'vacation_payout_tax' => 'TABLE',
        'net_pay_rounding' => 'ORE',
        'segregation_of_duties' => true,
        'use_employment_dimensions' => true,
        'payment_method' => 'PAIN001',
        'payslip_delivery' => 'PORTAL',
        'employee_self_service' => true,
        'agi_submission_mode' => 'FILE',
        // Skatteverket's declaration days depend on the company's taxable turnover.
        'agi_deadline_group' => 'TURNOVER_UP_TO_40_MSEK',
        'holiday_calendar' => 'SE',
        // Lag (2001:1170) om regionalt stöd: the company confirms a fast driftställe in the stödområde and the conditions in 2–4 §§ (#78).
        'regional_support' => false,
    ];
}

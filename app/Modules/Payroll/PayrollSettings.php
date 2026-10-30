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
        'net_pay_rounding' => ['ORE', 'WHOLE_KRONOR'],
        'segregation_of_duties' => null,
        'use_employment_dimensions' => null,
        'payment_method' => ['PAIN001', 'BANKGIROT_SALARY', 'MANUAL'],
        'payslip_delivery' => ['PORTAL', 'PORTAL_AND_EMAIL_NOTICE'],
        'employee_self_service' => null,
        'agi_submission_mode' => ['FILE', 'API'],
        'holiday_calendar' => ['SE'],
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
        'net_pay_rounding' => 'ORE',
        'segregation_of_duties' => true,
        'use_employment_dimensions' => true,
        'payment_method' => 'PAIN001',
        'payslip_delivery' => 'PORTAL',
        'employee_self_service' => true,
        'agi_submission_mode' => 'FILE',
        'holiday_calendar' => 'SE',
    ];
}

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http;

use App\Modules\Payroll\Support\Migration\PayrollCsv;
use Illuminate\Http\Response;

/**
 * An empty CSV file with the columns for one step of moving payroll data.
 */
class MigrationTemplateController
{
    private const array TEMPLATES = [
        'anstallda' => [PayrollCsv::EMPLOYEES, PayrollCsv::EMPLOYEES_OPTIONAL],
        'hittills-i-ar' => [PayrollCsv::YEAR_TO_DATE, PayrollCsv::YEAR_TO_DATE_OPTIONAL],
        'semester' => [PayrollCsv::VACATION, PayrollCsv::VACATION_OPTIONAL],
    ];

    public function __invoke(string $template): Response
    {
        abort_unless(isset(self::TEMPLATES[$template]), 404);

        [$required, $optional] = self::TEMPLATES[$template];

        return response("\u{FEFF}".implode(';', [...$required, ...$optional])."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$template}.csv\"",
        ]);
    }
}

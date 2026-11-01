<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Paxml;

/**
 * One transaction read from a payroll file, as the file wrote it: numbers
 * are the file's decimal text (point as decimal sign), dates Y-m-d. The
 * personal number is only carried to match the employee; it is never
 * stored or logged.
 */
final readonly class PayrollFileLine
{
    public const string SALARY = 'lonetrans';

    public const string TIME = 'tidtrans';

    public const string CSV = 'csv';

    public function __construct(
        public int $lineNo,
        public string $section,
        public ?string $employeeRef,
        public ?string $personalNumber,
        public ?string $code,
        public ?string $description,
        public ?string $dateFrom,
        public ?string $dateTo,
        public ?string $quantity,
        public ?string $rate,
        public ?string $amount,
    ) {}
}

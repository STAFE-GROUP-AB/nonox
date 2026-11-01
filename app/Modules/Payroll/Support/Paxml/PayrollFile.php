<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Paxml;

/**
 * A payroll file as read: its format and version, and the transactions
 * that can become payroll inputs. Schedules and personnel records in a
 * PAXml file are counted, never read.
 */
final readonly class PayrollFile
{
    /**
     * @param  list<PayrollFileLine>  $lines
     * @param  array<string, int>  $ignoredSections  section name => elements left unread
     */
    public function __construct(
        public string $format,
        public ?string $version,
        public array $lines,
        public array $ignoredSections = [],
        public ?string $program = null,
    ) {}
}

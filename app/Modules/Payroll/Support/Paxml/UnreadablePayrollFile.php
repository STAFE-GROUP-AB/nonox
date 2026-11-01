<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Paxml;

use RuntimeException;

/**
 * The file cannot be read as a payroll file. The message is for the user,
 * in Swedish, and never quotes the file's content.
 */
final class UnreadablePayrollFile extends RuntimeException {}

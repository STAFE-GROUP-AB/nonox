<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

/**
 * Something about an employee's day the manager should see before attesting
 * it. A blocking deviation keeps the day from being attested until it is
 * fixed (a missing check-out, time already reported elsewhere); an
 * informational one is shown but never counts as a deviation.
 */
final readonly class Deviation
{
    public const string EARLY_START = 'EARLY_START';

    public const string LATE_START = 'LATE_START';

    public const string EARLY_FINISH = 'EARLY_FINISH';

    public const string LATE_FINISH = 'LATE_FINISH';

    public const string MISSING_CHECK_OUT = 'MISSING_CHECK_OUT';

    public const string MISSING_CHECK_IN = 'MISSING_CHECK_IN';

    public const string UNPLANNED = 'UNPLANNED';

    public const string NO_SHOW = 'NO_SHOW';

    public const string ABSENCE = 'ABSENCE';

    public const string MANUAL = 'MANUAL';

    public const string EXTRA_TIME = 'EXTRA_TIME';

    public const string SHORT_TIME = 'SHORT_TIME';

    public const string AGREEMENT_UNVERIFIED = 'AGREEMENT_UNVERIFIED';

    public const string OB_WITH_BREAK = 'OB_WITH_BREAK';

    public const string OB_NOTE = 'OB_NOTE';

    public const string NO_OB = 'NO_OB';

    public const string WEEK_PENDING = 'WEEK_PENDING';

    public const string NO_EMPLOYMENT = 'NO_EMPLOYMENT';

    public const string ALREADY_REPORTED = 'ALREADY_REPORTED';

    public const string NOT_IN_LEDGER = 'NOT_IN_LEDGER';

    public function __construct(
        public string $code,
        public string $message,
        public bool $blocking = false,
        public bool $informational = false,
    ) {}

    public static function blocking(string $code, string $message): self
    {
        return new self($code, $message, blocking: true);
    }

    public static function info(string $code, string $message): self
    {
        return new self($code, $message, informational: true);
    }

    /** @return array{code: string, message: string, blocking: bool, informational: bool} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message, 'blocking' => $this->blocking, 'informational' => $this->informational];
    }
}

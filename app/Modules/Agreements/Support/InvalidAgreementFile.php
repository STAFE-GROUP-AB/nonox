<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Support;

use RuntimeException;

/**
 * An agreement data file that does not follow nonox.agreement/1; nothing
 * is imported from it.
 */
final class InvalidAgreementFile extends RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}

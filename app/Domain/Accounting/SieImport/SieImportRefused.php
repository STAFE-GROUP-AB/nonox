<?php

declare(strict_types=1);

namespace App\Domain\Accounting\SieImport;

use RuntimeException;

/**
 * A SIE import that stopped before anything was written, with the reasons in
 * Swedish and, when the reconciliation failed, its result.
 */
final class SieImportRefused extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>|null  $result
     */
    public function __construct(public readonly array $reasons, public readonly ?array $result = null)
    {
        parent::__construct(implode(' ', $reasons));
    }
}

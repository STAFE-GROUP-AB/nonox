<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support\GozeroCore;

use RuntimeException;

/**
 * GoZero Core refused or failed an analysis. The message is written for the
 * person in front of the screen; the code is Core's error code.
 */
final class GozeroCoreException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, public readonly ?string $requestId = null)
    {
        parent::__construct($message);
    }
}

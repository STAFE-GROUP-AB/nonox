<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Providers;

use RuntimeException;

/**
 * A calendar provider refused or failed. The message is for logs and never
 * holds a token. `reconnect`: the grant is gone and the user must connect
 * again. `resync`: the change cursor expired and everything is read again.
 */
final class CalendarProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $reconnect = false, public readonly bool $resync = false)
    {
        parent::__construct($message);
    }
}

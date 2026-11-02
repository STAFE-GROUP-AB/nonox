<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain;

enum ConnectionStatus: string
{
    case Active = 'ACTIVE';

    /** The till no longer accepts the credentials; connect again. */
    case NeedsReconnect = 'NEEDS_RECONNECT';

    case Disconnected = 'DISCONNECTED';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Kopplad'),
            self::NeedsReconnect => __('Behöver kopplas igen'),
            self::Disconnected => __('Bortkopplad'),
        };
    }
}

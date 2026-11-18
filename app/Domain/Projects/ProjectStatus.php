<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * Where a project is in its life (ADR-081 gap 6). The status is for
 * following projects up; it does not stop anyone from booking on the
 * project. Only deactivating it (is_active, ADR-009) hides it from new rows.
 */
enum ProjectStatus: string
{
    case Planned = 'PLANNED';
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Planned => __('Planerat'),
            self::Active => __('Pågående'),
            self::Completed => __('Avslutat'),
            self::Closed => __('Stängt'),
        };
    }
}

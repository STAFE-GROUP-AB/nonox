<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/** Why a contract version was recorded (ADR-080 §3.1). */
enum VersionCause: string
{
    case Created = 'CREATED';
    case Changed = 'CHANGED';
    case Paused = 'PAUSED';
    case Resumed = 'RESUMED';
    case CancelScheduled = 'CANCEL_SCHEDULED';
    case Ended = 'ENDED';
    case Backfill = 'BACKFILL';
    case Import = 'IMPORT';

    public function label(): string
    {
        return match ($this) {
            self::Created => __('Nytt avtal'),
            self::Changed => __('Ändrat'),
            self::Paused => __('Pausat'),
            self::Resumed => __('Återupptaget'),
            self::CancelScheduled => __('Uppsagt'),
            self::Ended => __('Avslutat'),
            self::Backfill => __('Inläst när modulen slogs på'),
            self::Import => __('Importerat'),
        };
    }
}

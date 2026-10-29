<?php

declare(strict_types=1);

namespace App\Domain\Accounting\SieImport;

/**
 * The steps of a SIE import: the file is checked first, and read in only
 * after the user has seen the report and confirmed.
 */
enum SieImportStatus: string
{
    case Analysed = 'ANALYSED';
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Analysed => __('Granskad, väntar på dig'),
            self::Queued => __('Väntar på att läsas in'),
            self::Running => __('Läses in'),
            self::Completed => __('Inläst'),
            self::Failed => __('Misslyckades'),
        };
    }

    public function isInProgress(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}

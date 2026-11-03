<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * Where a deal stands (ADR-043 stage 2). An open deal sits in a stage of its
 * pipeline; won and lost close it. Won and lost are statuses, not stages.
 */
enum DealStatus: string
{
    case Open = 'OPEN';
    case Won = 'WON';
    case Lost = 'LOST';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Öppen'),
            self::Won => __('Vunnen'),
            self::Lost => __('Förlorad'),
        };
    }

    /** The tone of the status badge (x-ui.badge). */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'accent',
            self::Won => 'success',
            self::Lost => 'neutral',
        };
    }
}

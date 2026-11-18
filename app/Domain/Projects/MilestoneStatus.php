<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * Where a milestone of a project's billing plan is (ADR-081 gap 6, E8):
 * planned, ready to invoice, or invoiced (a draft invoice is linked).
 */
enum MilestoneStatus: string
{
    case Planned = 'PLANNED';
    case Ready = 'READY';
    case Invoiced = 'INVOICED';

    public function label(): string
    {
        return match ($this) {
            self::Planned => __('Planerad'),
            self::Ready => __('Klar att fakturera'),
            self::Invoiced => __('Fakturerad'),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

/**
 * DRAFT → PLANNED → IN_PROGRESS → DONE → CLOSED, or CANCELLED (ADR-074 §2).
 * DONE stops reporting; CLOSED means everything is invoiced or written off
 * as not billable.
 */
enum WorkOrderStatus: string
{
    case Draft = 'DRAFT';
    case Planned = 'PLANNED';
    case InProgress = 'IN_PROGRESS';
    case Done = 'DONE';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Planned => __('Planerad'),
            self::InProgress => __('Pågår'),
            self::Done => __('Klar'),
            self::Closed => __('Avslutad'),
            self::Cancelled => __('Makulerad'),
        };
    }

    /** Time and material can still be reported. */
    public function acceptsReports(): bool
    {
        return in_array($this, [self::Planned, self::InProgress], true);
    }

    /** Open on the books: not closed or cancelled. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Closed, self::Cancelled], true);
    }

    /** @return list<self> where a work order may go from here */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Planned, self::InProgress, self::Cancelled],
            self::Planned => [self::InProgress, self::Draft, self::Cancelled],
            self::InProgress => [self::Done, self::Planned],
            self::Done => [self::Closed, self::InProgress],
            self::Closed, self::Cancelled => [],
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/**
 * Where a production order is (ADR-071 §8): a draft is still being
 * planned and its copy can change; a released order reserves its
 * components; a started order has begun and may have components issued;
 * a finished order has issued the rest and received the finished goods.
 * A cancelled order is kept with its number; components it had taken are
 * returned to stock.
 *
 *     DRAFT → RELEASED → STARTED → FINISHED
 *       ↑________↓          (RELEASED may go back to DRAFT)
 *     DRAFT, RELEASED, STARTED → CANCELLED
 */
enum ProductionOrderStatus: string
{
    case Draft = 'DRAFT';
    case Released = 'RELEASED';
    case Started = 'STARTED';
    case Finished = 'FINISHED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Released => __('Frisläppt'),
            self::Started => __('Startad'),
            self::Finished => __('Färdig'),
            self::Cancelled => __('Makulerad'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled => 'neutral',
            self::Released, self::Started => 'accent',
            self::Finished => 'success',
        };
    }

    /** @return list<self> the statuses it may move to next */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Released, self::Cancelled],
            self::Released => [self::Draft, self::Started, self::Cancelled],
            self::Started => [self::Finished, self::Cancelled],
            self::Finished, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $status): bool
    {
        return in_array($status, $this->next(), true);
    }

    /** Released or started: it reserves its components and is work to be done (§5). */
    public function reserves(): bool
    {
        return in_array($this, [self::Released, self::Started], true);
    }

    /** Not finished or cancelled. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Released, self::Started], true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Draft, self::Released, self::Started];
    }
}

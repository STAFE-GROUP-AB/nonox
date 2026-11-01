<?php

declare(strict_types=1);

namespace App\Domain\Overview;

use Carbon\CarbonImmutable;

/**
 * One thing to do on Översikt: what, why, by when and where to do it. Kept
 * as plain data, so the list can be cached (the cache store refuses objects).
 */
final readonly class TodoItem
{
    /** Order inside the list when no deadline decides: lower comes first. */
    public const int PRIORITY_VAT = 10;

    public const int PRIORITY_AGI = 20;

    public const int PRIORITY_OVERDUE_INVOICE = 30;

    public const int PRIORITY_APPROVE = 40;

    public const int PRIORITY_PAY = 45;

    public const int PRIORITY_BANK = 50;

    public const int PRIORITY_INBOX = 60;

    public const int PRIORITY_PAYROLL = 70;

    public const int PRIORITY_STAFF_LEDGER = 80;

    public const int PRIORITY_DRAFTS = 85;

    public const int PRIORITY_CLOSING = 90;

    public const int PRIORITY_OTHER = 100;

    /** Icons the overview knows how to draw (resources/views/components/overview/icon.blade.php). */
    public const array ICONS = ['vat', 'payroll', 'invoice', 'approve', 'pay', 'bank', 'inbox', 'clock', 'draft', 'calendar', 'task'];

    /** Due within this many days, or already overdue, counts as urgent. */
    public const int URGENT_DAYS = 7;

    public function __construct(
        public string $key,
        public string $icon,
        public string $title,
        public ?string $subtitle,
        public string $url,
        public int $priority = self::PRIORITY_OTHER,
        public ?CarbonImmutable $due = null,
    ) {}

    public function isOverdue(CarbonImmutable $today): bool
    {
        return $this->due !== null && $this->due->lessThan($today->startOfDay());
    }

    public function isUrgent(CarbonImmutable $today): bool
    {
        return $this->due !== null && $this->due->lessThanOrEqualTo($today->startOfDay()->addDays(self::URGENT_DAYS));
    }

    /**
     * @return array{key: string, icon: string, title: string, subtitle: ?string, url: string, priority: int, due: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'icon' => in_array($this->icon, self::ICONS, true) ? $this->icon : 'task',
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'url' => $this->url,
            'priority' => $this->priority,
            'due' => $this->due?->toDateString(),
        ];
    }

    /**
     * Deadlines first (the nearest at the top, overdue ones before them),
     * then by kind: VAT, employer declarations, overdue invoices, approvals,
     * bank, inbox, payroll, staff ledger, drafts, closing.
     *
     * @param  list<array{key: string, icon: string, title: string, subtitle: ?string, url: string, priority: int, due: ?string}>  $items
     * @return list<array{key: string, icon: string, title: string, subtitle: ?string, url: string, priority: int, due: ?string}>
     */
    public static function sort(array $items): array
    {
        usort($items, fn (array $a, array $b): int => [$a['due'] === null ? 1 : 0, $a['due'] ?? '', $a['priority'], $a['title']]
            <=> [$b['due'] === null ? 1 : 0, $b['due'] ?? '', $b['priority'], $b['title']]);

        return $items;
    }
}

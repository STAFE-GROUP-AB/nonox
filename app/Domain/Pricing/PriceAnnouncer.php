<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Events\PriceListChanged;
use Closure;

/**
 * Announces price list changes to the webhooks (ADR-082 P9). A bulk write
 * (the API's price list items, a mass update) runs inside batch(), so a
 * list that changed many times is announced once, with the earliest date.
 */
final class PriceAnnouncer
{
    /** @var ?array<string, array{company: int, list: int, from: ?string}> */
    private static ?array $pending = null;

    public static function listChanged(int $companyId, int $listId, ?string $from = null): void
    {
        if (self::$pending === null) {
            event(new PriceListChanged($companyId, $listId, $from));

            return;
        }

        $key = "{$companyId}:{$listId}";
        $kept = self::$pending[$key] ?? null;

        // The earliest day wins; a change from the start (null) beats any date.
        self::$pending[$key] = [
            'company' => $companyId,
            'list' => $listId,
            'from' => $kept === null ? $from : (($kept['from'] === null || $from === null) ? null : min($kept['from'], $from)),
        ];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function batch(Closure $work): mixed
    {
        if (self::$pending !== null) {
            return $work();
        }

        self::$pending = [];

        try {
            return $work();
        } finally {
            $pending = self::$pending ?? [];
            self::$pending = null;

            foreach ($pending as $change) {
                event(new PriceListChanged($change['company'], $change['list'], $change['from']));
            }
        }
    }
}

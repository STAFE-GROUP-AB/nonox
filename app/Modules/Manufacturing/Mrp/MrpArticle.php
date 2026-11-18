<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

/**
 * One article the engine plans (ADR-081 gap 5): what is on hand, its
 * planning data, and, when it is made, the bill of materials valid today
 * (its batch and its component rows, in milli-units of each component's
 * stock unit, with the loss in basis points).
 */
final readonly class MrpArticle
{
    /**
     * @param  ?array{yield_milli: int, rows: list<array{component_article_id: int, quantity_milli: int, loss_bp: int}>}  $bom
     */
    public function __construct(
        public int $id,
        public PlannedOrderKind $kind,
        public int $onHandMilli,
        public int $safetyStockMilli,
        public int $leadTimeDays,
        public ?int $lotMinimumMilli,
        public ?int $lotMultipleMilli,
        public ?int $supplierId,
        public ?array $bom = null,
    ) {}

    /**
     * The quantity to order for a net requirement: at least the minimum lot,
     * then rounded up to a whole multiple.
     */
    public function lotSize(int $netMilli): int
    {
        $quantity = max($netMilli, $this->lotMinimumMilli ?? 0);

        if ($this->lotMultipleMilli !== null && $this->lotMultipleMilli > 0) {
            $quantity = intdiv($quantity + $this->lotMultipleMilli - 1, $this->lotMultipleMilli) * $this->lotMultipleMilli;
        }

        return $quantity;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'on_hand_milli' => $this->onHandMilli,
            'safety_stock_milli' => $this->safetyStockMilli,
            'lead_time_days' => $this->leadTimeDays,
            'lot_minimum_milli' => $this->lotMinimumMilli,
            'lot_multiple_milli' => $this->lotMultipleMilli,
            'supplier_id' => $this->supplierId,
            'bom' => $this->bom,
        ];
    }
}

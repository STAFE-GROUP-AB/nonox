<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

/**
 * A scheduled receipt (ADR-081 gap 5): what an open purchase order row or an
 * open production order still brings in, and the date it is expected.
 */
final readonly class MrpReceipt
{
    public function __construct(
        public int $articleId,
        public SupplySource $source,
        public int $sourceId,
        public int $number,
        public string $date,
        public int $quantityMilli,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'article_id' => $this->articleId,
            'source' => $this->source->value,
            'id' => $this->sourceId,
            'number' => $this->number,
            'on' => $this->date,
            'quantity_milli' => $this->quantityMilli,
        ];
    }
}

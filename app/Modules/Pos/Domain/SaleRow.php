<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain;

/**
 * One line of a till sale, as the driver read it. The quantity is signed
 * thousandths: positive sold, negative returned. Lines without goods (a
 * custom amount, a gift card) carry isGoods = false and never move stock.
 */
final readonly class SaleRow
{
    public function __construct(
        public int $lineNo,
        public string $name,
        public int $quantityMilli,
        public int $amountOre,
        public ?int $vatRate,
        public bool $isGoods,
        public ?string $externalProductId = null,
        public ?string $externalVariantId = null,
        public ?string $barcode = null,
        public ?string $sku = null,
    ) {}
}

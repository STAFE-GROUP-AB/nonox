<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

/**
 * A dated requirement of an article (ADR-081 gap 5): a customer order, a
 * component of an open production order or of a parent's planned order, or
 * the forecast above the orders of a week. The reference is what the
 * pegging shows: the order's id and number, or the parent article.
 */
final readonly class MrpDemand
{
    public function __construct(
        public int $articleId,
        public DemandKind $kind,
        public string $date,
        public int $quantityMilli,
        public string|int|null $sourceId = null,
        public ?int $number = null,
        public ?int $parentArticleId = null,
    ) {}

    /** @return array{kind: string, quantity_milli: int, on: string, id: string|int|null, number: ?int, article_id: ?int} */
    public function peg(int $quantityMilli): array
    {
        return [
            'kind' => $this->kind->value,
            'quantity_milli' => $quantityMilli,
            'on' => $this->date,
            'id' => $this->sourceId,
            'number' => $this->number,
            'article_id' => $this->parentArticleId,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['article_id' => $this->articleId, ...$this->peg($this->quantityMilli)];
    }
}

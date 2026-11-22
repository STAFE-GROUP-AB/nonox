<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Modules\Inventory\Domain\StructureMath;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The margin of a whole invoice, quote or order (ADR-082 §9): per row what it
 * costs and whether it sells below cost or below the company's floor, and the
 * document's contribution (täckningsbidrag) and contribution margin
 * (täckningsgrad) over the rows whose cost is known. Internal: the figures
 * are for people with prices:view-cost and never go on a customer's PDF, in
 * the portal or in the API.
 *
 * Only a document in kronor is checked: the costs are kept in kronor, and a
 * price in another currency would need a rate. A document in another currency
 * is not checked but not let through either: while the company requires
 * approval, a typed price on an article row needs it (unchecked, ADR-082 §9).
 *
 * A row is manual when a person typed its price, a discount on top of a list
 * price, or the row itself (free text). Negative rows (a discount row, a
 * return) lower the sales of the document, so the document's contribution
 * margin is judged as a whole too: a manual row that lowers the margin (a
 * negative row, a discounted row, a typed price under the floor) on a
 * document whose margin is under the floor needs approval even when no single
 * row is. A positive free-text row (freight) or a row of 0 kr lowers nothing
 * and holds nothing.
 *
 * A fixed number of queries whatever the number of rows (ArticleCostBook).
 */
final readonly class DocumentMargin
{
    /**
     * @param  array<int, RowMargin>  $rows  by the caller's row index
     * @param  list<int>  $manualBelowFloor  the indexes of manual rows with a cost whose own margin is under the floor
     * @param  list<int>  $loweringRows  the indexes of manual rows that lower the margin: every negative row, discounted rows and typed prices under the floor (in another currency: every typed article row)
     * @param  bool  $unchecked  a document in another currency: no margin, only the manual article rows are known
     */
    public function __construct(
        public array $rows,
        public array $manualBelowFloor,
        public int $netOre,
        public int $costOre,
        public int $uncosted,
        public PriceFloor $floor,
        public array $loweringRows = [],
        public bool $unchecked = false,
    ) {}

    /**
     * @param  list<array{article_id: ?int, quantity: string, unit: string, net_ore: int, manual: bool, lowers?: bool}>  $lines  by index; a free-text row has no article; lowers: a negative or discounted row
     * @param  ?ArticleCostBook  $book  the costs of every article of the lines, when the caller has loaded them for many documents
     */
    public static function assess(Company $company, array $lines, ?CarbonImmutable $date = null, ?ArticleCostBook $book = null): self
    {
        $floor = PriceFloor::of($company);
        $ids = array_values(array_filter(array_column($lines, 'article_id')));
        $book ??= ArticleCostBook::for($company, $ids, $date);

        $rows = [];
        $manualBelow = [];
        $lowering = [];
        $net = 0;
        $cost = 0;
        $uncosted = 0;

        // What the document sells of each article, as cost: a return takes cost back only up to this (ADR-082 §9).
        $soldCost = [];

        foreach ($lines as $line) {
            if ($line['article_id'] !== null && $line['net_ore'] >= 0 && ! str_starts_with(ltrim($line['quantity']), '-') && BigDecimal::of($line['quantity'] === '' ? '0' : $line['quantity'])->isPositive()) {
                $soldCost[$line['article_id']] = ($soldCost[$line['article_id']] ?? 0) + (int) $book->costOf((int) $line['article_id'], $line['quantity'], $line['unit']);
            }
        }

        foreach ($lines as $index => $line) {
            // A return (negative quantity, negative amount) of an article sold on the document takes its cost back, up to the cost sold and the row's amount, so a real partial return keeps the margin and a return never raises it; any other negative row has no cost.
            $returned = str_starts_with(ltrim($line['quantity']), '-');
            $costable = $returned ? $line['net_ore'] < 0 : $line['net_ore'] >= 0;
            $rowCost = $line['article_id'] === null || ! $costable || BigDecimal::of($line['quantity'] === '' ? '0' : $line['quantity'])->isZero()
                ? null
                : $book->costOf((int) $line['article_id'], $line['quantity'], $line['unit']);

            if ($rowCost !== null && $returned) {
                // Only the quantity sold on this document takes its cost back, and never more than the row's own amount: a return can never raise the margin.
                $take = min(-$rowCost, $soldCost[$line['article_id']] ?? 0, -$line['net_ore']);
                $soldCost[$line['article_id']] = ($soldCost[$line['article_id']] ?? 0) - $take;
                $rowCost = -$take;
            }

            $row = RowMargin::of($line['net_ore'], $rowCost, $floor->minimumBp);
            $rows[$index] = $row;

            // Every negative row lowers the margin, typed or not: a return at a list price too.
            if ($line['net_ore'] < 0 || ($line['manual'] && ($line['lowers'] ?? false))) {
                $lowering[] = $index;
            }

            if ($rowCost === null) {
                $uncosted += $line['net_ore'] > 0 ? 1 : 0;
                // A discount row or a return lowers what the document sells for.
                $net += $line['net_ore'] < 0 ? $line['net_ore'] : 0;

                continue;
            }

            $net += $row->netOre;
            $cost += $rowCost;

            if ($line['manual'] && $row->belowFloor) {
                $manualBelow[] = $index;
                $lowering[] = $index;
            }
        }

        return new self($rows, $manualBelow, $net, $cost, $uncosted, $floor, array_values(array_unique($lowering)));
    }

    /** A saved invoice, quote or order, from its stored rows. */
    public static function ofDocument(Company $company, Invoice|SalesDocument $document, ?ArticleCostBook $book = null): self
    {
        $rows = $document->relationLoaded('rows') ? $document->rows : $document->rows()->get();
        $foreign = $document instanceof Invoice ? $document->invoice_currency !== 'SEK' : $document->currency !== 'SEK';
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = [
                'article_id' => $row->article_id,
                'quantity' => (string) $row->quantity,
                'unit' => (string) $row->unit,
                'net_ore' => $row->net_ore->ore,
                'manual' => self::isManual($row->article_id, $row->price_source, $row->discount_bp),
                'lowers' => $row->net_ore->ore < 0 || $row->discount_bp > 0,
            ];
        }

        if ($foreign) {
            $typed = [];

            foreach ($lines as $index => $line) {
                // A typed article price cannot be compared with a cost in kronor; a discount or negative row lowers what is sold.
                if ($line['net_ore'] < 0 || ($line['manual'] && ($line['article_id'] !== null || $line['lowers']))) {
                    $typed[] = $index;
                }
            }

            return new self([], [], 0, 0, 0, PriceFloor::of($company), $typed, true);
        }

        return self::assess($company, $lines, null, $book);
    }

    /**
     * Whether a person set the row's price: typed it, typed a discount on top
     * of a price from a list or an agreement, or wrote the row without an
     * article.
     */
    public static function isManual(?int $articleId, ?PriceSource $source, int $discountBp): bool
    {
        return $articleId === null || $source === PriceSource::Manual || $discountBp > 0;
    }

    public function contributionOre(): int
    {
        return $this->netOre - $this->costOre;
    }

    public function marginBp(): ?int
    {
        return StructureMath::share($this->contributionOre(), $this->netOre);
    }

    /** Whether the document as a whole sells under the floor (over the rows with a known cost and the negative rows). */
    public function documentBelowFloor(): bool
    {
        if ($this->unchecked || $this->floor->minimumBp === null || $this->costOre <= 0) {
            return false;
        }

        return $this->netOre <= 0 || $this->contributionOre() * 10000 < $this->floor->minimumBp * $this->netOre;
    }

    /** Whether a manual price needs an approval: a manual row under the floor, or a manual row on a document under it. */
    public function needsApproval(): bool
    {
        if (! $this->floor->requiresApproval()) {
            return false;
        }

        if ($this->unchecked) {
            return $this->loweringRows !== [];
        }

        return $this->manualBelowFloor !== [] || ($this->documentBelowFloor() && $this->loweringRows !== []);
    }

    /**
     * The rows that need the approval, as indexes: the manual rows under the
     * floor, else the manual rows that lower the margin (the document as a
     * whole is under the floor, or it is in another currency).
     *
     * @return list<int>
     */
    public function approvalRows(): array
    {
        return $this->manualBelowFloor !== [] && ! $this->unchecked ? $this->manualBelowFloor : $this->loweringRows;
    }
}

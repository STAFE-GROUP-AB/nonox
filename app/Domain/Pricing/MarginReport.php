<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Company;
use App\Support\Money\Money;

/**
 * What the editors' margin check tells a person about the rows they are
 * writing (ADR-082 §9, P7), as the browser shows it: a text per row under
 * cost or under the floor, the document's contribution, and a banner when a
 * manual price needs approval before the document can be sent.
 *
 * Cost and contribution only for $viewCost (prices:view-cost). Without it the
 * answer says that a price is under the company's floor, since that decides
 * whether the document can be sent, but never what anything costs.
 */
final class MarginReport
{
    /**
     * @param  list<array{article_id: ?int, quantity: string, unit: string, net_ore: int, manual: bool}>  $lines
     * @param  list<int>  $indexes  the editor's row index of each line
     * @return array{rows: array<int, array{below_cost: bool, below_floor: bool, needs_approval: bool, text: string}>, totals: ?array{net: string, cost: string, contribution: string, margin: string, uncosted: int}, banner: string, note: string}
     */
    public static function for(Company $company, array $lines, array $indexes, bool $viewCost): array
    {
        $margin = DocumentMargin::assess($company, $lines);
        $floor = $margin->floor;
        $money = fn (int $ore): string => Money::ofOre($ore)->format();
        $rows = [];

        foreach ($margin->rows as $position => $row) {
            $belowCost = $viewCost && $row->belowCost;
            $needsApproval = $row->belowFloor && $lines[$position]['manual'] && $floor->requiresApproval();

            if (! $belowCost && ! $row->belowFloor) {
                continue;
            }

            $text = $belowCost
                ? __('Under självkostnad: raden ger :net och kostar :cost.', ['net' => $money($row->netOre), 'cost' => $money((int) $row->costOre)])
                : __('Under lägsta täckningsgrad (:floor).', ['floor' => $floor->label()]);

            $rows[$indexes[$position]] = ['below_cost' => $belowCost, 'below_floor' => $row->belowFloor, 'needs_approval' => $needsApproval, 'text' => $text];
        }

        $lineNumbers = array_map(fn (int $position): int => $indexes[$position] + 1, $margin->approvalRows());

        return [
            'rows' => $rows,
            'totals' => $viewCost && $margin->netOre > 0 ? [
                'net' => $money($margin->netOre),
                'cost' => $money($margin->costOre),
                'contribution' => $money($margin->contributionOre()),
                'margin' => PriceFloor::percent((int) $margin->marginBp()),
                'uncosted' => $margin->uncosted,
            ] : null,
            'banner' => $margin->needsApproval()
                ? __('Priset på rad :lines ligger under lägsta täckningsgrad (:floor). Dokumentet kan inte skickas eller skapas förrän någon med behörighet har godkänt priserna.', ['lines' => implode(', ', $lineNumbers), 'floor' => $floor->label()])
                : '',
            'note' => '',
        ];
    }
}

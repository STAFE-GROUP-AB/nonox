<?php

declare(strict_types=1);

namespace App\Actions\Budget;

use App\Domain\Budget\LockedBudgetVersion;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Support\Tenancy\ArchivedCompany;

/**
 * Writes a version's lines in bulk: one INSERT per 500 lines instead of one
 * per cell. Bulk inserts skip model events, so the company and the lock are
 * checked here; the database trigger still refuses a locked version's lines.
 * Only called by the budget actions, inside their transaction.
 */
final class BudgetLines
{
    /**
     * @param  list<array{account_id: int, cost_center_id: ?int, project_id: ?int, period: string, amount_ore: int, is_override?: bool}>  $lines
     * @return int the number of lines written (zero amounts are skipped, except a forecast month typed by hand)
     */
    public static function insert(BudgetVersion $version, array $lines): int
    {
        if ($version->isLocked()) {
            throw LockedBudgetVersion::make();
        }

        // An archived company is read-only (ADR-024); model events would have said so.
        ArchivedCompany::guardWrite(new BudgetLine, $version->company_id);

        $now = now();
        $rows = [];

        foreach ($lines as $line) {
            $override = $line['is_override'] ?? false;

            if ($line['amount_ore'] === 0 && ! $override) {
                continue;
            }

            $rows[] = [
                'company_id' => $version->company_id,
                'budget_version_id' => $version->id,
                'account_id' => $line['account_id'],
                'cost_center_id' => $line['cost_center_id'],
                'project_id' => $line['project_id'],
                'period' => $line['period'],
                'amount_ore' => $line['amount_ore'],
                'is_override' => $override,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            BudgetLine::query()->insert($chunk);
        }

        return count($rows);
    }
}

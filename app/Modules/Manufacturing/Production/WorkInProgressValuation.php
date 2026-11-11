<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Production;

use App\Models\Company;
use App\Modules\Inventory\Support\StockValuationAddition;
use App\Modules\Manufacturing\ManufacturingSettings;
use Carbon\CarbonImmutable;

/**
 * The work in progress of unfinished production orders in the year-end
 * stock valuation (ADR-071 §8), on its own account pair (Produkter i
 * arbete, 1440/4940 by default). Finished goods need nothing extra: they
 * are articles in stock, received at their manufacturing cost.
 *
 * TODO(legal-verify): the BAS accounts for work in progress and finished
 * goods (1440/1450) and their change accounts (4940, 4950 or 4970).
 */
final class WorkInProgressValuation implements StockValuationAddition
{
    public const string KEY = 'WORK_IN_PROGRESS';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Produkter i arbete');
    }

    public function accounts(Company $company): array
    {
        return ManufacturingSettings::wipAccounts($company);
    }

    public function accountNames(): array
    {
        return ManufacturingSettings::ACCOUNT_NAMES;
    }

    public function value(Company $company, CarbonImmutable $date): array
    {
        $wip = WorkInProgress::at($company, $date);

        return [
            'value_ore' => $wip->valueOre,
            'lines' => array_map(fn (array $line): array => ['reference' => $line['reference'], 'name' => $line['name'], 'value_ore' => $line['value_ore']], $wip->lines),
        ];
    }
}

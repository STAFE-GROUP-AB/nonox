<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use App\Domain\Analysis\Datasets\BudgetDataset;
use App\Domain\Analysis\Datasets\CustomerInvoicesDataset;
use App\Domain\Analysis\Datasets\InvoiceRowsDataset;
use App\Domain\Analysis\Datasets\LedgerDataset;
use App\Domain\Analysis\Datasets\PayrollDataset;
use App\Domain\Analysis\Datasets\SupplierInvoiceRowsDataset;
use App\Models\Company;
use App\Models\User;

/**
 * The fixed catalogue of datasets (ADR-081 gap 7, E17). A saved report holds a
 * dataset's key, never SQL; each dataset checks its own permission every time
 * it is used, so a shared report shows nothing to a member who may not read it.
 *
 * Not yet in the catalogue: stock, purchase orders, production and work orders
 * and the project register as datasets of their own (the ledger already groups
 * by project); each is one class that implements Dataset.
 */
final class DatasetCatalogue
{
    /** @return list<Dataset> */
    public static function all(): array
    {
        return [
            new LedgerDataset,
            new BudgetDataset,
            new CustomerInvoicesDataset,
            new InvoiceRowsDataset,
            new SupplierInvoiceRowsDataset,
            new PayrollDataset,
        ];
    }

    /**
     * The datasets the member may use, by key.
     *
     * @return array<string, Dataset>
     */
    public static function for(User $user, Company $company): array
    {
        $datasets = [];

        foreach (self::all() as $dataset) {
            if ($dataset->allows($user, $company)) {
                $datasets[$dataset->key()] = $dataset;
            }
        }

        return $datasets;
    }
}

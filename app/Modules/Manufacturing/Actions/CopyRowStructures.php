<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions;

use App\Models\SalesDocument;
use App\Modules\Manufacturing\Models\RowStructure;
use App\Modules\Manufacturing\Models\RowStructureLine;
use App\Support\Tenancy\CurrentCompany;

/**
 * The copies follow the quote into the order (ADR-071 §4): each row copy
 * of the quote is copied to the order's row with the same line_key, as
 * it stands. Called when ProgressSalesDocument links the new order to its
 * quote, inside the same transaction and after the permission checks of
 * turning a quote into an order; the module need not be on, so a copy is
 * never lost while the module is off.
 */
class CopyRowStructures
{
    public function __invoke(SalesDocument $from, SalesDocument $to): int
    {
        return CurrentCompany::run($to->company_id, function () use ($from, $to): int {
            $keys = $to->rows()->pluck('line_key')->filter()->flip();
            $existing = RowStructure::query()->where('sales_document_id', $to->id)->pluck('line_key')->flip();
            $copied = 0;

            foreach (RowStructure::query()->where('sales_document_id', $from->id)->with('lines')->get() as $source) {
                if (! $keys->has($source->line_key) || $existing->has($source->line_key)) {
                    continue;
                }

                $copy = RowStructure::create([
                    ...$source->only(['line_key', 'output_article_id', 'bill_of_materials_id', 'name', 'yield_milli', 'changed', 'created_by', 'updated_by']),
                    'sales_document_id' => $to->id,
                ]);

                foreach ($source->lines as $line) {
                    /** @var RowStructureLine $line */
                    RowStructureLine::create([
                        ...$line->only(['position', 'component_article_id', 'quantity_milli', 'loss_bp', 'entered_quantity', 'entered_unit', 'operation_id', 'minutes', 'note']),
                        'row_structure_id' => $copy->id,
                    ]);
                }

                $copied++;
            }

            return $copied;
        });
    }
}

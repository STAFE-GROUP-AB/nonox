<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseMatchResult;
use App\Modules\Inventory\Purchasing\Matching\MatchLine;
use App\Modules\Inventory\Purchasing\Matching\MatchOutcome;
use App\Modules\Inventory\Purchasing\Matching\MatchStatus;
use App\Modules\Inventory\Purchasing\Matching\MatchTolerances;
use App\Modules\Inventory\Purchasing\Matching\ThreeWayMatch;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Compares a registered supplier invoice with its purchase orders and goods
 * receipts again and stores the result when it differs from the stored one
 * (ADR-081 E11). The old set is superseded, never deleted. An override
 * follows to the new set only for a row whose comparison is exactly the same,
 * so an override never covers figures nobody judged.
 *
 * Only a registered invoice is compared: once it is approved, the results it
 * was approved with stay as they were. A credit note is never compared. Books
 * nothing and approves nothing. Derived data, so anyone who may see the
 * invoice's purchase orders may cause it to be computed.
 */
class RecomputePurchaseMatch
{
    public function __invoke(Company $company, SupplierInvoice $invoice, ?User $by = null): MatchOutcome
    {
        return CurrentCompany::run($company, fn (): MatchOutcome => DB::transaction(function () use ($company, $invoice, $by): MatchOutcome {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $current = self::current($invoice);

            if (! self::compares($company, $invoice)) {
                return new MatchOutcome($current, false, $current->isNotEmpty());
            }

            $lines = ThreeWayMatch::compute($invoice, MatchTolerances::for($company));
            $fresh = collect($lines)->map(fn (MatchLine $line): string => $line->fingerprint())->sort()->values()->all();

            if ($fresh === $current->pluck('fingerprint')->sort()->values()->all()) {
                return new MatchOutcome($current, false, $current->isNotEmpty());
            }

            $now = now();

            if ($current->isNotEmpty()) {
                PurchaseMatchResult::query()->whereKey($current->pluck('id')->all())->update(['superseded_at' => $now]);
            }

            $overridden = $current->filter(fn (PurchaseMatchResult $result): bool => $result->status === MatchStatus::Overridden)->keyBy('fingerprint');
            $carried = 0;
            $rows = [];

            foreach ($lines as $line) {
                $attributes = $line->attributes();
                $previous = $overridden->get($line->fingerprint());

                $row = [
                    ...$attributes,
                    'company_id' => $company->id,
                    'supplier_invoice_id' => $invoice->id,
                    'invoice_row_ids' => json_encode($attributes['invoice_row_ids']),
                    'reasons' => json_encode($attributes['reasons']),
                    'status' => $line->status->value,
                    'override_reason' => null,
                    'overridden_by' => null,
                    'overridden_at' => null,
                    'carried_from_id' => null,
                    'computed_at' => $now,
                    'computed_by' => $by?->id,
                ];

                if ($previous instanceof PurchaseMatchResult) {
                    $carried++;
                    $row = [
                        ...$row,
                        'status' => MatchStatus::Overridden->value,
                        'override_reason' => $previous->override_reason,
                        'overridden_by' => $previous->overridden_by,
                        'overridden_at' => $previous->overridden_at,
                        'carried_from_id' => $previous->id,
                    ];
                }

                $rows[] = $row;
            }

            if ($rows !== []) {
                PurchaseMatchResult::query()->insert($rows);
            }

            $results = self::current($invoice);

            Audit::record('inventory.purchase_match_computed', $invoice, [
                'supplier_invoice_id' => $invoice->id,
                'rows' => $results->count(),
                'open' => $results->filter(fn (PurchaseMatchResult $result): bool => $result->isOpen())->count(),
                'carried_overrides' => $carried,
            ], $company, $by);

            return new MatchOutcome($results, true, $current->isNotEmpty());
        }));
    }

    /** Whether the invoice is compared now: Lager on, a registered invoice (not a credit note). */
    public static function compares(Company $company, SupplierInvoice $invoice): bool
    {
        return ! $invoice->isCredit()
            && $invoice->status === SupplierInvoiceStatus::Registered
            && app(ModuleManager::class)->isEnabled(InventoryModule::KEY, $company);
    }

    /** @return EloquentCollection<int, PurchaseMatchResult> the invoice's current results, in the order they were computed */
    public static function current(SupplierInvoice $invoice): EloquentCollection
    {
        return PurchaseMatchResult::query()->where('supplier_invoice_id', $invoice->id)->whereNull('superseded_at')->orderBy('id')->get();
    }
}

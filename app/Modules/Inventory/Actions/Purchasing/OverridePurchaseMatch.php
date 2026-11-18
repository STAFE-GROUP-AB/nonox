<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseMatchResult;
use App\Modules\Inventory\Purchasing\Matching\MatchStatus;
use App\Modules\Inventory\Purchasing\Matching\PurchaseMatchAttest;
use App\Modules\ModuleManager;
use App\Policies\SupplierInvoicePolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Accepts a deviation of the three-way comparison with a reason (ADR-081
 * E11): someone who may approve the invoice now (the default attest, or the
 * current step of the company's own approval flow) judges that the row may
 * be paid as invoiced. The result keeps its figures; only its status, the
 * reason, who and when are added, once, and the database refuses anything
 * else. The override is bound to the result the person saw: when the
 * comparison has been made again since, the old result is superseded and the
 * override is refused. While the company requires separate approval, the
 * person who registered the invoice or allocated its rows cannot override.
 *
 * The reason stays on the result; the behandlingshistorik gets the ids only.
 */
class OverridePurchaseMatch
{
    public const int REASON_MAX = 500;

    public function __invoke(User $user, Company $company, SupplierInvoice $invoice, int $resultId, string $reason): PurchaseMatchResult
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);
        $invoice = CurrentCompany::run($company, fn (): SupplierInvoice => SupplierInvoice::query()->findOrFail($invoice->id));
        // A result that is not on this invoice answers 404 before any permission check, so it reveals nothing.
        CurrentCompany::run($company, fn () => PurchaseMatchResult::query()->where('supplier_invoice_id', $invoice->id)->whereKey($resultId)->firstOrFail());
        // The same right as the attest itself: the default attest, or deciding the current step of the company's own flow.
        Gate::forUser($user)->authorize('approve', $invoice);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['overrideReason' => __('Skriv varför avvikelsen kan godkännas.')]);
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw ValidationException::withMessages(['overrideReason' => __('Skälet får vara högst :max tecken.', ['max' => self::REASON_MAX])]);
        }

        return CurrentCompany::run($company, fn (): PurchaseMatchResult => DB::transaction(function () use ($user, $company, $invoice, $resultId, $reason): PurchaseMatchResult {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            /** @var PurchaseMatchResult $result */
            $result = PurchaseMatchResult::query()->where('supplier_invoice_id', $invoice->id)->whereKey($resultId)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== SupplierInvoiceStatus::Registered) {
                throw ValidationException::withMessages(['overrideReason' => __('Fakturan väntar inte på attest.')]);
            }

            if ((new SupplierInvoicePolicy)->blockedBySegregation($user, $invoice) || PurchaseMatchAttest::preparedBy($company, $user, $invoice)) {
                throw ValidationException::withMessages(['overrideReason' => __('Du har förberett fakturan och kan därför inte godkänna avvikelsen. Någon annan med rätt att attestera behöver göra det.')]);
            }

            if ($result->superseded_at !== null) {
                throw ValidationException::withMessages(['overrideReason' => __('Avstämningen har ändrats sedan den visades. Titta på den nya och godkänn avvikelsen där om den fortfarande kan godkännas.')]);
            }

            if (! $result->isOpen()) {
                throw ValidationException::withMessages(['overrideReason' => __('Raden avviker inte från inköpsordern utanför toleranserna.')]);
            }

            $from = $result->status;

            $result->forceFill([
                'status' => MatchStatus::Overridden,
                'override_reason' => $reason,
                'overridden_by' => $user->id,
                'overridden_at' => now(),
            ])->save();

            Audit::record('inventory.purchase_match_overridden', $invoice, ['supplier_invoice_id' => $invoice->id, 'result_id' => $result->id, 'from' => $from->value], $company, $user);

            return $result;
        }));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Matching;

use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inventory\Actions\Purchasing\RecomputePurchaseMatch;
use App\Modules\Inventory\Models\PurchaseMatchAllocation;
use App\Modules\Inventory\Models\PurchaseMatchResult;
use App\Modules\Inventory\Models\PurchaseOrderInvoice;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The attest hook of the three-way comparison (ADR-081 E11, owner decision
 * 10). ApproveSupplierInvoice calls check() with the invoice locked, before
 * each approval, on the default single attest and on every step of a
 * company's own approval flow alike.
 *
 * - An invoice not matched with any purchase order, a credit note, and a
 *   company without Lager are not affected.
 * - The invoice is compared again first. When the comparison has deviations
 *   and is not the one the person saw (the token of the invoice page, or for
 *   a call without one, the set stored before), the approval is refused so
 *   the person looks again: an approval is never given on figures nobody saw.
 * - While the company requires separate approval, whoever allocated the
 *   invoice's rows to order rows has prepared it and may not approve it.
 * - Deviations outside the tolerances that nobody has overridden block the
 *   approval when the company has chosen so; otherwise the approval goes
 *   ahead and the behandlingshistorik records that it was given with open
 *   deviations (a count, nothing else).
 */
final class PurchaseMatchAttest
{
    public function __construct(private readonly RecomputePurchaseMatch $recompute) {}

    public function check(User $user, SupplierInvoice $invoice, ?string $seen = null): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);

        if (! self::applies($company, $invoice)) {
            return;
        }

        CurrentCompany::run($company, function () use ($user, $company, $invoice, $seen): void {
            if (self::preparedBy($company, $user, $invoice)) {
                throw ValidationException::withMessages(['approve' => __('Du fördelade fakturans rader mot inköpsordern och kan därför inte attestera den. Någon annan med rätt att attestera behöver göra det.')]);
            }

            $outcome = ($this->recompute)($company, $invoice, $user);
            $open = $outcome->open();

            if ($open->isEmpty()) {
                return;
            }

            $unseen = $seen !== null ? self::token($outcome->results) !== $seen : $outcome->changed && $outcome->hadPrevious;

            if ($unseen) {
                throw ValidationException::withMessages(['approve' => __('Avstämningen mot inköpsordern har ändrats sedan den visades. Ladda om sidan och titta på den igen innan du attesterar.')]);
            }

            if (MatchTolerances::for($company)->blocksAttest) {
                throw ValidationException::withMessages(['approve' => trans_choice('{1} En rad avviker från inköpsordern utanför toleranserna. Den behöver godkännas med ett skäl innan fakturan kan attesteras.|[2,*] :count rader avviker från inköpsordern utanför toleranserna. De behöver godkännas med ett skäl innan fakturan kan attesteras.', $open->count(), ['count' => $open->count()])]);
            }

            Audit::record('inventory.purchase_match_attested_with_deviations', $invoice, ['supplier_invoice_id' => $invoice->id, 'open' => $open->count()], $company, $user);
        });
    }

    /**
     * Compares the invoice (stored when it changed) and returns the token of
     * what the page now shows, or null when the comparison does not apply.
     */
    public function seen(Company $company, SupplierInvoice $invoice, ?User $by = null): ?string
    {
        if (! self::applies($company, $invoice)) {
            return null;
        }

        return self::token(($this->recompute)($company, $invoice, $by)->results);
    }

    /** Whether the hook concerns the invoice: compared now (RecomputePurchaseMatch::compares) and matched with an order. */
    public static function applies(Company $company, SupplierInvoice $invoice): bool
    {
        return RecomputePurchaseMatch::compares($company, $invoice)
            && CurrentCompany::run($company, fn (): bool => PurchaseOrderInvoice::query()->where('supplier_invoice_id', $invoice->id)->exists());
    }

    /**
     * The version of the comparison a person saw: the current results and
     * their status. A new comparison (new rows) or an override changes it.
     *
     * @param  Collection<int, PurchaseMatchResult>  $results
     */
    public static function token(Collection $results): string
    {
        return hash('sha256', (string) json_encode($results->sortBy('id')->map(fn (PurchaseMatchResult $result): array => [$result->id, $result->status->value])->values()->all()));
    }

    /**
     * Whether the user prepared the comparison (allocated a row of it) while
     * the company requires someone else to approve: then they may neither
     * approve the invoice nor override a deviation on it.
     */
    public static function preparedBy(Company $company, User $user, SupplierInvoice $invoice): bool
    {
        return $company->separate_supplier_approval
            && CurrentCompany::run($company, fn (): bool => PurchaseMatchAllocation::query()->where('supplier_invoice_id', $invoice->id)->where('allocated_by', $user->id)->exists());
    }
}

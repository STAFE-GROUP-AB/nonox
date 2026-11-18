<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\SubjectFacts;
use App\Models\ApprovalRequest;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\PurchaseOrderApprovalMode;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders and the approval engine (ADR-081 E10).
 *
 * The engine's subject is the order and its approval round ("12.3"): the
 * round is raised when the order's rows are saved again or a sent order gets
 * its next version, so an approval never carries over to something other than
 * what was approved, and every round has its own request and decisions. The
 * company's amount threshold (purchase_order_approval_above_ore, ADR-071) is
 * the default policy: it applies when no policy of the company's own does,
 * which is why nothing changes for a company that never edits a policy.
 *
 * Runs inside the company.
 */
final class PurchaseOrderApprovals
{
    public static function subjectId(PurchaseOrder $order): string
    {
        return $order->id.'.'.$order->approval_round;
    }

    /**
     * Locks the order a subject id names, in the current company (another
     * company's order is not found) and checks it is still on that round.
     */
    public static function lock(string $subjectId): PurchaseOrder
    {
        $parts = explode('.', $subjectId);

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            throw new ModelNotFoundException;
        }

        $order = PurchaseOrder::query()->whereKey((int) $parts[0])->lockForUpdate()->firstOrFail();

        if ($order->approval_round !== (int) $parts[1]) {
            throw ValidationException::withMessages(['approve' => __('Inköpsordern har ändrats sedan du öppnade sidan. Ladda om den och försök igen.')]);
        }

        return $order;
    }

    public static function mode(PurchaseOrder $order, Company $company): PurchaseOrderApprovalMode
    {
        $order->loadMissing('rows');

        if (self::hasPolicy($order)) {
            return PurchaseOrderApprovalMode::Policy;
        }

        $limit = InventorySettings::purchaseApprovalAboveOre($company);

        return $limit !== null && $order->totalOre() > $limit ? PurchaseOrderApprovalMode::Threshold : PurchaseOrderApprovalMode::None;
    }

    /** Whether a policy of the company's own applies to the order, or it already has a request. */
    public static function hasPolicy(PurchaseOrder $order): bool
    {
        $order->loadMissing('rows');

        return app(ApprovalEngine::class)->hasCustomFlow($order->company_id, ApprovalSubjectType::PurchaseOrder, self::subjectId($order), fn (): SubjectFacts => SubjectFacts::forPurchaseOrder($order));
    }

    /**
     * An order that is saved again or revised starts a new approval round:
     * an open request, if there is one, is withdrawn, and an approval or rejection stays in the
     * history of the round it belonged to. Call it inside a transaction with
     * the order locked.
     */
    public static function startNewRound(PurchaseOrder $order, User $actor): void
    {
        $hasRequest = ApprovalRequest::query()
            ->where('company_id', $order->company_id)
            ->where('subject_type', ApprovalSubjectType::PurchaseOrder->value)
            ->where('subject_id', self::subjectId($order))
            ->exists();

        $had = $hasRequest || $order->approval_request_id !== null;

        if ($had) {
            app(ApprovalEngine::class)->withdraw(SubjectFacts::forPurchaseOrder($order), $actor);
        }

        // Always a new round, so an approver who opened the page before this save is refused (the page carries the round it showed).
        $order->forceFill([
            'approval_round' => $order->approval_round + 1,
            'approval_request_id' => null,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        if ($had) {
            Audit::record('inventory.purchase_order_approval_reset', $order, ['number' => $order->number, 'version' => $order->version, 'round' => $order->approval_round], $order->company_id, $actor);
        }
    }
}

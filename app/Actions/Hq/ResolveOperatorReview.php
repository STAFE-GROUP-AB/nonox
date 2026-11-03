<?php

declare(strict_types=1);

namespace App\Actions\Hq;

use App\Jobs\SyncToOperator;
use App\Models\OperatorLink;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An HQ admin's decision on a link waiting in NEEDS_REVIEW (ADR-044):
 * Koppla (link the company or queue entry to the operator's existing
 * customer, account or person) or Skapa ny (create new records). The
 * decision is stored on the link and the sync runs again. Audited with ids
 * and the decision only.
 */
final class ResolveOperatorReview
{
    public function __invoke(User $admin, OperatorLink $link, string $decision): void
    {
        HqAccess::ensure($admin);

        if (! in_array($decision, [OperatorLink::DECISION_LINK, OperatorLink::DECISION_NEW], true)) {
            throw ValidationException::withMessages(['review' => __('Välj Koppla eller Skapa ny.')]);
        }

        DB::transaction(function () use ($admin, $link, $decision): void {
            $link = OperatorLink::query()->lockForUpdate()->findOrFail($link->id);

            if ($link->status !== OperatorLink::NEEDS_REVIEW || $link->review_decision !== null) {
                throw ValidationException::withMessages(['review' => __('Det här är redan avgjort.')]);
            }

            $link->forceFill(['review_decision' => $decision, 'status' => OperatorLink::PENDING])->save();

            Audit::instance('hq.operator_review_resolved', $link, [
                'decision' => $decision,
                'source_company_id' => $link->source_company_id,
                'waitlist_entry_id' => $link->waitlist_entry_id,
                'review_customer_id' => $link->review_customer_id,
                'review_account_id' => $link->review_account_id,
                'review_person_id' => $link->review_person_id,
            ], $admin);
        });

        if ($link->source_company_id !== null) {
            SyncToOperator::dispatch(SyncToOperator::COMPANY, $link->source_company_id);
        } elseif ($link->waitlist_entry_id !== null) {
            SyncToOperator::dispatch(SyncToOperator::WAITLIST, $link->waitlist_entry_id);
        }
    }
}

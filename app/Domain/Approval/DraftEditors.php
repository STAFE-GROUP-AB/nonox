<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use Illuminate\Support\Facades\DB;

/**
 * Who has edited a subject, read from the audit log (behandlingshistorik).
 * For segregation of duties everyone who edits a subject counts as one of
 * its preparers, not only the person who created it (ADR-067 §6, ADR-081
 * E21): a person who changed a draft's rows or attachments may not approve
 * it while the step or the company keeps the preparer out.
 *
 * One query for any number of subjects, on the audit log's subject index.
 */
final class DraftEditors
{
    /** Saving a manual voucher draft and adding or removing its attachments. */
    public const array VOUCHER_ACTIONS = ['voucher.draft_saved', 'voucher.attachment_added', 'voucher.attachment_removed'];

    /** Saving a credit invoice draft. */
    public const array INVOICE_ACTIONS = ['invoice.draft_saved'];

    /** Saving a purchase order and opening its next version (ADR-081 E10). */
    public const array PURCHASE_ORDER_ACTIONS = ['inventory.purchase_order_saved', 'inventory.purchase_order_revised'];

    /** Saving a purchase requisition (ADR-081 E9). */
    public const array REQUISITION_ACTIONS = ['inventory.purchase_requisition_saved'];

    /**
     * @param  array<int, string>  $subjectIds
     * @param  list<string>  $actions
     * @return array<int|string, list<int>> subject id => user ids, first edit first
     */
    public static function of(int $companyId, string $morphClass, array $subjectIds, array $actions): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $rows = DB::table('audit_logs')
            ->where('company_id', $companyId)
            ->where('subject_type', $morphClass)
            ->whereIn('subject_id', array_values($subjectIds))
            ->whereIn('action', $actions)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get(['subject_id', 'user_id']);

        $editors = [];

        foreach ($rows as $row) {
            $id = (string) $row->subject_id;
            $editors[$id] ??= [];

            if (! in_array((int) $row->user_id, $editors[$id], true)) {
                $editors[$id][] = (int) $row->user_id;
            }
        }

        return $editors;
    }
}

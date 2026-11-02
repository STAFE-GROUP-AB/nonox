<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Voucher;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxMessage;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;

/**
 * When a draft made from an inbox message is deleted, the message no longer
 * points at it. Once none of its drafts is left, the message goes back to
 * "Att granska" so it can be approved again (or deleted, since nothing is
 * booked from it). Runs on the voucher's deleted event, inside the
 * transaction that removed the draft.
 */
class ForgetDeletedDraft
{
    public function __invoke(Voucher $voucher): void
    {
        CurrentCompany::run($voucher->company_id, function () use ($voucher): void {
            $messages = InboxMessage::query()
                ->where('status', MessageStatus::DraftCreated->value)
                ->whereJsonContains('voucher_ids', $voucher->id)
                ->lockForUpdate()
                ->get();

            foreach ($messages as $message) {
                $remaining = array_values(array_filter($message->voucher_ids, fn (string $id): bool => $id !== $voucher->id));
                $reopened = $remaining === [] && $message->supplier_invoice_id === null;

                $message->forceFill([
                    'voucher_ids' => $remaining,
                    'status' => $reopened ? MessageStatus::NeedsReview : $message->status,
                    'handled_by' => $reopened ? null : $message->handled_by,
                    'handled_at' => $reopened ? null : $message->handled_at,
                ])->save();

                Audit::record($reopened ? 'inbox.reopened' : 'inbox.draft_removed', $message, ['voucher' => $voucher->id], $voucher->company_id);
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Console;

use App\Modules\Inbox\Actions\AcceptInboundMail;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Models\UnassignedMessage;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes inbox mail that never became räkenskapsinformation (GDPR art. 5.1
 * e, docs/gdpr/lagringstider.md): rejected messages after 90 days, and mail
 * to the shared address that no company claimed after 30 days. A message
 * linked to a voucher or supplier invoice is never removed here; it is kept
 * with the bookkeeping.
 *
 * TODO(legal-verify): whether a rejected document can still be
 * räkenskapsinformation (BFL 5 kap. 7 §) when it was rejected as a
 * duplicate of one that was booked.
 */
class PruneInboxCommand extends Command
{
    public const int REJECTED_DAYS = 90;

    public const int UNASSIGNED_DAYS = 30;

    protected $signature = 'inbox:prune';

    protected $description = 'Remove rejected inbox mail and unclaimed shared-address mail past their retention';

    public function handle(): int
    {
        $disk = Storage::disk(AcceptInboundMail::DISK);

        // Retention runs across every company; each message's own files are removed with it.
        $removed = CompanyScope::bypass(function () use ($disk): int {
            $messages = InboxMessage::query()
                ->where('status', MessageStatus::Rejected->value)
                ->where('handled_at', '<', now()->subDays(self::REJECTED_DAYS))
                ->whereNull('supplier_invoice_id')
                ->whereJsonLength('voucher_ids', 0)
                ->with('attachments')
                ->get();

            foreach ($messages as $message) {
                $this->info("Removing inbox message `{$message->id}`...");
                $disk->delete([$message->eml_path, ...$message->attachments->map(fn (InboxAttachment $attachment): string => $attachment->path)->all()]);
                $message->delete();
            }

            return $messages->count();
        });

        $unclaimed = UnassignedMessage::query()->whereNull('claimed_at')->where('received_at', '<', now()->subDays(self::UNASSIGNED_DAYS))->get();

        foreach ($unclaimed as $message) {
            $disk->delete($message->eml_path);
            $message->delete();
        }

        $this->comment("Removed {$removed} rejected and {$unclaimed->count()} unclaimed messages.");

        return self::SUCCESS;
    }
}

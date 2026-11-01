<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Jobs\InterpretInboxMessageJob;
use App\Modules\Inbox\Models\AllowedSender;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\InboxChecks;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A person's decisions on messages in quarantine or review: allow the
 * sender (adds them to the allowlist and releases their waiting mail),
 * release a message after looking at it, reject it, read it again or
 * delete it.
 */
class InboxReview
{
    /**
     * Adds the sender's address to the allowlist and releases every message
     * from them that waited only for that. Messages held for another reason
     * (failed authentication, spam, a blocked attachment) stay in quarantine.
     *
     * @return int how many messages were released
     */
    public function allowSender(User $user, InboxMessage $message): int
    {
        $company = $this->authorize($user, $message);

        return CurrentCompany::run($company, function () use ($user, $company, $message): int {
            $sender = AllowedSender::query()->firstOrCreate(['pattern' => mb_strtolower($message->from_email)], ['created_by' => $user->id, 'note' => __('Godkänd från inkorgen')]);

            if ($sender->wasRecentlyCreated) {
                Audit::record('inbox.sender_allowed', $sender, ['pattern' => $sender->pattern, 'from_message' => $message->id], $company, $user);
            }

            $released = 0;
            $waiting = InboxMessage::query()->where('status', MessageStatus::Quarantine->value)->where('from_email', $message->from_email)->get();

            foreach ($waiting as $held) {
                $reasons = array_values(array_filter($held->quarantine_reasons, fn (array $reason): bool => $reason['code'] !== 'NOT_ALLOWLISTED'));
                $held->forceFill(['quarantine_reasons' => $reasons])->save();

                if ($reasons === []) {
                    $this->release($user, $company, $held);
                    $released++;
                }
            }

            return $released;
        });
    }

    /**
     * Releases a message after a person has looked at why it was held. The
     * sender must be on the allowlist. Blocked attachments stay blocked, and
     * a message whose authentication failed is never booked automatically.
     */
    public function releaseAfterReview(User $user, InboxMessage $message): void
    {
        $company = $this->authorize($user, $message);

        CurrentCompany::run($company, function () use ($user, $company, $message): void {
            if ($message->status !== MessageStatus::Quarantine) {
                throw ValidationException::withMessages(['message' => __('Meddelandet ligger inte i karantän.')]);
            }

            if (! InboxChecks::isAllowed($message->from_email)) {
                throw ValidationException::withMessages(['message' => __('Godkänn avsändaren först.')]);
            }

            $this->release($user, $company, $message);
        });
    }

    public function reject(User $user, InboxMessage $message, string $reason = ''): void
    {
        $company = $this->authorize($user, $message);

        CurrentCompany::run($company, function () use ($user, $company, $message, $reason): void {
            DB::transaction(function () use ($user, $company, $message, $reason): void {
                /** @var InboxMessage $locked */
                $locked = InboxMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();

                if (! $locked->status->isOpen()) {
                    throw ValidationException::withMessages(['message' => __('Meddelandet är redan hanterat.')]);
                }

                $locked->forceFill([
                    'status' => MessageStatus::Rejected,
                    'rejection_reason' => trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500),
                    'handled_by' => $user->id,
                    'handled_at' => now(),
                ])->save();

                Audit::record('inbox.rejected', $locked, ['reason' => $locked->rejection_reason, 'from' => $locked->from_email], $company, $user);
            });
        });
    }

    /**
     * Reads a message in review again, for instance after NONOX AI failed or
     * a template was saved for the sender.
     */
    public function interpretAgain(User $user, InboxMessage $message): void
    {
        $company = $this->authorize($user, $message);

        if (! in_array($message->status, [MessageStatus::NeedsReview, MessageStatus::DraftReady], true)) {
            throw ValidationException::withMessages(['message' => __('Bara underlag som väntar på granskning kan läsas igen.')]);
        }

        Audit::record('inbox.interpret_again', $message, [], $company, $user);
        InterpretInboxMessageJob::dispatch($company->id, $message->id);
    }

    /**
     * Deletes a message and its files. A message that became a voucher or a
     * supplier invoice, or a draft of one, is underlag (räkenskapsinformation,
     * Bokföringslagen 7 kap.) and is kept.
     */
    public function delete(User $user, InboxMessage $message): void
    {
        $company = $this->authorize($user, $message);

        CurrentCompany::run($company, function () use ($user, $company, $message): void {
            $paths = DB::transaction(function () use ($user, $company, $message): array {
                /** @var InboxMessage $locked */
                $locked = InboxMessage::query()->with('attachments')->whereKey($message->id)->lockForUpdate()->firstOrFail();

                if (in_array($locked->status, [MessageStatus::Booked, MessageStatus::DraftCreated], true) || $locked->voucher_ids !== [] || $locked->supplier_invoice_id !== null) {
                    throw ValidationException::withMessages(['message' => __('Underlaget är bokfört och sparas i sju år enligt bokföringslagen. Det går inte att radera.')]);
                }

                $paths = [$locked->eml_path, ...$locked->attachments->map(fn (InboxAttachment $attachment): string => $attachment->path)->all()];
                Audit::record('inbox.deleted', null, ['message' => $locked->id, 'from' => $locked->from_email, 'subject' => $locked->subject, 'status' => $locked->status->value], $company, $user);
                $locked->delete();

                return $paths;
            });

            Storage::disk(AcceptInboundMail::DISK)->delete($paths);
        });
    }

    private function release(User $user, Company $company, InboxMessage $message): void
    {
        $message->forceFill([
            'status' => MessageStatus::NeedsReview,
            'quarantine_reasons' => [],
            'released_by' => $user->id,
            'released_at' => now(),
            'warnings' => [...$message->warnings, ...array_map(
                fn (array $reason): array => ['code' => 'RELEASED_'.$reason['code'], 'message' => __('Släppt efter granskning: :reason', ['reason' => $reason['message']])],
                array_values(array_filter($message->quarantine_reasons, fn (array $reason): bool => $reason['code'] !== 'NOT_ALLOWLISTED')),
            )],
        ])->save();

        Audit::record('inbox.released', $message, ['from' => $message->from_email], $company, $user);
        InterpretInboxMessageJob::dispatch($company->id, $message->id);
    }

    private function authorize(User $user, InboxMessage $message): Company
    {
        $company = Company::query()->findOrFail($message->company_id);

        if (! $user->hasTeamPermission($company, 'inbox:manage')) {
            throw new AuthorizationException;
        }

        return $company;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Models\UnassignedMessage;
use App\Modules\Inbox\Support\InboxAddress;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A member of one of the candidate companies takes a message from the
 * shared address into their company's inbox. It then goes through the
 * same checks as any other message. Only one company can claim it.
 */
class ClaimUnassignedMessage
{
    public function __construct(private readonly AcceptInboundMail $accept) {}

    public function __invoke(User $user, Company $company, string $unassignedId): InboxMessage
    {
        return DB::transaction(function () use ($user, $company, $unassignedId): InboxMessage {
            $unassigned = UnassignedMessage::query()->whereKey($unassignedId)->lockForUpdate()->first();

            if (! $unassigned instanceof UnassignedMessage || ! $unassigned->isCandidate($company)) {
                // Do not reveal that the message exists to anyone else.
                throw ValidationException::withMessages(['message' => __('Meddelandet finns inte eller har redan hämtats av ett annat företag.')]);
            }

            if (! $unassigned->canBeClaimedBy($user, $company)) {
                $unassigned->claimed_at === null
                    ? throw new AuthorizationException
                    : throw ValidationException::withMessages(['message' => __('Meddelandet finns inte eller har redan hämtats av ett annat företag.')]);
            }

            $raw = (string) Storage::disk(AcceptInboundMail::DISK)->get($unassigned->eml_path);
            $alias = InboxAddress::parse($unassigned->recipient)[1] ?? null;
            $message = ($this->accept)($company, $raw, Channel::Mailgun, $unassigned->recipient, $alias, (array) $unassigned->transport)
                ?? throw ValidationException::withMessages(['message' => __('Inkorgen är inte påslagen för företaget.')]);

            $unassigned->forceFill(['claimed_company_id' => $company->id, 'claimed_by' => $user->id, 'claimed_at' => now()])->save();
            Storage::disk(AcceptInboundMail::DISK)->delete($unassigned->eml_path);

            Audit::record('inbox.claimed', $message, ['unassigned_id' => $unassigned->id, 'from' => $unassigned->from_email], $company, $user);

            return $message;
        });
    }
}

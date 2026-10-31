<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Mail\InboxActionNeededMail;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\Inbox\Support\InboxSettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the company's inbox handlers by e-mail that something waits for
 * them, when the company has asked for it. At most once an hour, so a busy
 * inbox does not flood anyone; the in-app count is always shown.
 */
class NotifyInbox
{
    public function __invoke(Company $company): void
    {
        if (! InboxSettings::for($company)['notify_on_action']) {
            return;
        }

        CurrentCompany::run($company, function () use ($company): void {
            $mailbox = InboxAddress::mailbox($company);

            if ($mailbox->notified_at !== null && $mailbox->notified_at->gt(now()->subHour())) {
                return;
            }

            $waiting = InboxMessage::query()->whereIn('status', array_map(fn (MessageStatus $status): string => $status->value, MessageStatus::tabs()['att-granska']))->count();

            if ($waiting === 0) {
                return;
            }

            foreach (self::recipients($company) as $user) {
                Mail::to($user->email)->send(new InboxActionNeededMail($company->name, $waiting));
            }

            $mailbox->forceFill(['notified_at' => now()])->save();
        });
    }

    /**
     * Members who handle the inbox.
     *
     * @return Collection<int, User>
     */
    public static function recipients(Company $company): Collection
    {
        return $company->allUsers()->filter(fn (User $user): bool => $user->hasTeamPermission($company, 'inbox:manage'))->values();
    }
}

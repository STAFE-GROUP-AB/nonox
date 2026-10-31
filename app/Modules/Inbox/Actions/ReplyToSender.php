<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\Mail\InboxReplyMail;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\InboxChecks;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Answers the sender of a message from the inbox, for example "saknar
 * kvitto", through NONOX's ordinary mailer and within the company's mail
 * quota. Only a sender on the allowlist whose mail passed authentication
 * is answered, so the inbox cannot be used to send mail to forged
 * addresses.
 */
class ReplyToSender
{
    public function __invoke(User $user, InboxMessage $message, string $text): void
    {
        $company = Company::query()->findOrFail($message->company_id);

        if (! $user->hasTeamPermission($company, 'inbox:manage')) {
            throw new AuthorizationException;
        }

        $text = trim($text);
        Validator::make(['replyText' => $text], ['replyText' => ['required', 'string', 'max:2000']], [], ['replyText' => __('svar')])->validate();

        CurrentCompany::run($company, function () use ($user, $company, $message, $text): void {
            if ($message->failedAuthentication() || ! InboxChecks::isAllowed($message->from_email)) {
                throw ValidationException::withMessages(['replyText' => __('Du kan bara svara en godkänd avsändare vars e-post gick igenom kontrollen.')]);
            }

            MailQuota::take($company, 'documents', 'replyText');
            Mail::to($message->from_email)->send(new InboxReplyMail($company->name, (string) ($message->subject ?? ''), $text, $user->email));

            Audit::record('inbox.replied', $message, ['to' => $message->from_email, 'text' => $text], $company, $user);
        });
    }
}

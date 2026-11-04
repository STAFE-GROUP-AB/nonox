<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\EmailDirection;
use App\Modules\Crm\Domain\EmailMatches;
use App\Modules\Crm\Domain\EmailStatus;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Support\CrmMailReader;
use App\Modules\Crm\Support\LoggedMail;
use App\Modules\Inbox\Actions\AcceptInboundMail;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use App\Modules\Inbox\Support\MailAuthentication;
use App\Modules\Inbox\Support\MailParser;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use App\Support\WorkQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Logs a message sent to the company's +crm address (ADR-048): a BCC from
 * someone's own mail client, or a message they forwarded.
 *
 * Who may log: the message must come from the sign-in address of a member
 * with crm:manage, and pass the same SPF/DKIM/DMARC check as the inbox
 * (ADR-023). Anything else is dropped without a bounce and audited, so the
 * address (which suppliers may also know, it is the inbox's with +crm) can
 * neither be used to put text on a customer's timeline nor to probe who
 * works at the company.
 *
 * Direction and parties: a BCC is outbound, and its parties are To and Cc.
 * A forward is read as its original: inbound unless the original came from
 * a member. The company's own people and addresses are never "the other
 * party". The parties are matched to accounts and people (EmailMatches),
 * whose rules decide whether it is logged, held or only stored.
 *
 * Kept: the subject, a 500-character excerpt on the timeline, and the full
 * plain text and the addresses encrypted in crm_emails. Attachments are not
 * kept, only their names: the original stays in the sender's own mailbox.
 */
final class LogInboundEmail
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly CrmMailReader $reader,
        private readonly MailParser $parser,
    ) {}

    /**
     * @param  array<string, string>  $transport  what the transport said beside the message (Mailgun's fields)
     *
     * @throws InboxQuotaExceeded
     */
    public function __invoke(Company $company, string $raw, Channel $channel, array $transport = []): ?Email
    {
        if (! $this->modules->isEnabled(CrmModule::KEY, $company) || $company->isArchived()) {
            Log::info('CRM: dropped a message to the +crm address of a company without CRM.', ['company_id' => $company->id]);

            return null;
        }

        if (strlen($raw) > AcceptInboundMail::maxBytes()) {
            Audit::record('crm.email_dropped', null, ['reason' => 'too_large', 'channel' => $channel->value], $company);

            return null;
        }

        $sha256 = hash('sha256', $raw);

        return CurrentCompany::run($company, function () use ($company, $raw, $channel, $transport, $sha256): ?Email {
            $existing = Email::query()->where('sha256', $sha256)->first();

            if ($existing instanceof Email) {
                return $existing;
            }

            if (! WorkQuota::attempt($company, 'crm_mail')) {
                throw new InboxQuotaExceeded;
            }

            $auth = MailAuthentication::evaluate($this->parser->parse($raw), $channel, $transport, $channel === Channel::Imap ? (string) config('services.inbox.imap_authserv_id') : null);

            if ((bool) $auth['failed'] || (bool) $auth['spam']) {
                Audit::record('crm.email_dropped', null, ['reason' => 'authentication', 'channel' => $channel->value, 'sha256' => $sha256], $company);

                return null;
            }

            $mail = $this->reader->read($raw);
            $sender = $this->member($company, $mail->from);

            if ($sender === null) {
                Audit::record('crm.email_dropped', null, ['reason' => 'not_member', 'channel' => $channel->value, 'sha256' => $sha256], $company);

                return null;
            }

            return $this->store($company, $sender, $mail, $channel, $sha256);
        });
    }

    private function store(Company $company, User $sender, LoggedMail $mail, Channel $channel, string $sha256): ?Email
    {
        $own = $this->ownAddresses($company);
        $original = $mail->forwarded ?? $mail;
        $direction = $mail->forwarded === null || isset($own[$mail->forwarded->from]) ? EmailDirection::Out : EmailDirection::In;
        $parties = $mail->forwarded === null ? [...$mail->to, ...$mail->cc] : $mail->forwarded->addresses();
        $other = array_values(array_filter(array_unique($parties), fn (string $address): bool => ! isset($own[$address]) && ! str_ends_with($address, '@'.InboxAddress::domain())));

        $matches = EmailMatches::find($other);
        $status = $matches->status($direction);

        // Restricted processing (art. 18) of a matched person or customer: nothing new is stored about them.
        if ($status === EmailStatus::Restricted) {
            Audit::record('crm.email_dropped', null, ['reason' => 'restricted', 'channel' => $channel->value, 'sha256' => $sha256], $company, $sender);

            return null;
        }

        return DB::transaction(function () use ($company, $sender, $mail, $original, $channel, $sha256, $direction, $other, $matches, $status): Email {
            $email = Email::create([
                'direction' => $direction,
                'status' => $status,
                'channel' => $channel->value,
                'subject' => $original->subject !== '' ? $original->subject : ($mail->subject !== '' ? $mail->subject : __('(inget ämne)')),
                'excerpt' => CrmMailReader::excerpt($original->text) ?: null,
                'body' => $original->text === '' ? null : $original->text,
                'participants' => [
                    'from' => $original->from,
                    'from_name' => $original->fromName,
                    'to' => $original->to,
                    'cc' => $original->cc,
                    'other' => $other,
                ],
                'attachment_names' => $mail->attachmentNames === [] ? null : $mail->attachmentNames,
                'message_id' => $original->messageId === null ? null : mb_substr($original->messageId, 0, 998),
                'sha256' => $sha256,
                'forwarded_by' => $sender->id,
                'person_id' => $matches->firstPersonId(),
                'sent_at' => $original->date ?? now(),
            ]);

            $activities = $status === EmailStatus::Logged ? self::logOn($email, $matches->personByAccount, $sender->id) : 0;

            Audit::record('crm.email_received', $email, ['status' => $status->value, 'direction' => $direction->value, 'channel' => $channel->value, 'activities' => $activities], $company, $sender);

            return $email;
        });
    }

    /**
     * Puts the e-mail on each account's timeline: a done EMAIL activity with
     * the subject and the excerpt, by and for the person who sent it in.
     *
     * @param  array<int, ?int>  $personByAccount
     * @return int how many activities
     */
    public static function logOn(Email $email, array $personByAccount, ?int $userId): int
    {
        $count = 0;

        foreach ($personByAccount as $accountId => $personId) {
            $activity = Activity::create([
                'account_id' => $accountId,
                'person_id' => $personId,
                'type' => 'EMAIL',
                'subject' => mb_substr(($email->direction === EmailDirection::In ? '← ' : '→ ').$email->subject, 0, 255),
                'body' => $email->excerpt,
                'assigned_to' => $userId,
                'created_by' => $userId,
                'done_at' => $email->sent_at,
                'email_id' => $email->id,
            ]);

            LogActivity::touch($activity);
            $count++;
        }

        return $count;
    }

    /** The member who signs in with this address and may write in the CRM. */
    private function member(Company $company, string $address): ?User
    {
        if ($address === '') {
            return null;
        }

        $user = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($address)])
            ->where(fn ($query) => $query
                ->whereKey($company->user_id)
                ->orWhereIn('id', fn ($members) => $members->select('user_id')->from('team_user')->where('team_id', $company->id)))
            ->first();

        return $user instanceof User && $user->hasTeamPermission($company, 'crm:manage') ? $user : null;
    }

    /**
     * The company's own addresses: every member's sign-in address and the
     * company's e-mail. They are never the other party.
     *
     * @return array<string, true>
     */
    private function ownAddresses(Company $company): array
    {
        $emails = User::query()
            ->where(fn ($query) => $query
                ->whereKey($company->user_id)
                ->orWhereIn('id', fn ($members) => $members->select('user_id')->from('team_user')->where('team_id', $company->id)))
            ->pluck('email')
            ->push((string) $company->email)
            ->map(fn (?string $email): string => mb_strtolower(trim((string) $email)))
            ->filter()
            ->all();

        return array_fill_keys($emails, true);
    }
}

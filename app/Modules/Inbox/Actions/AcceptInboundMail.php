<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Company;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Jobs\InterpretInboxMessageJob;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\AttachmentScreen;
use App\Modules\Inbox\Support\AttachmentType;
use App\Modules\Inbox\Support\InboxChecks;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use App\Modules\Inbox\Support\MailAuthentication;
use App\Modules\Inbox\Support\MailParser;
use App\Modules\Inbox\Support\ParsedMail;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\FileName;
use App\Support\Tenancy\CurrentCompany;
use App\Support\WorkQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Takes a raw message into a company's inbox. Security comes before any
 * reading of the contents (ADR-023):
 *
 * 1. the module is on, the message is not too large and the company has
 *    not received too much in the last hour;
 * 2. the .eml is stored byte for byte, and every attachment unaltered, on
 *    the encrypted private disk with its SHA-256;
 * 3. SPF/DKIM/DMARC and the spam flag are read, every attachment is
 *    scanned (clamd) or checked for dangerous types;
 * 4. a sender who is not on the allowlist, a failed authentication, spam
 *    or a blocked attachment puts the message in quarantine, where nothing
 *    is interpreted until a person releases it.
 *
 * Only then is the message handed to InterpretInboxMessage, in a queued
 * job that carries the company id.
 */
class AcceptInboundMail
{
    public const string DISK = 'local';

    public function __construct(
        private readonly MailParser $parser,
        private readonly AttachmentScreen $screen,
        private readonly ModuleManager $modules,
    ) {}

    public static function maxBytes(): int
    {
        return (int) config('services.inbox.max_message_kb', 25_600) * 1024;
    }

    /**
     * @param  array<string, string>  $transport  what the transport said beside the message (Mailgun's fields)
     * @return ?InboxMessage null when the message was dropped
     *
     * @throws InboxQuotaExceeded
     */
    public function __invoke(Company $company, string $raw, Channel $channel, string $recipient, ?string $alias = null, array $transport = [], ?ParsedMail $mail = null): ?InboxMessage
    {
        if (! $this->modules->isEnabled(InboxModule::KEY, $company)) {
            return null;
        }

        if (strlen($raw) > self::maxBytes()) {
            Audit::record('inbox.dropped', null, ['reason' => 'too_large', 'size' => strlen($raw), 'channel' => $channel->value], $company);

            return null;
        }

        $sha256 = hash('sha256', $raw);

        return CurrentCompany::run($company, function () use ($company, $raw, $channel, $recipient, $alias, $transport, $mail, $sha256): InboxMessage {
            $existing = InboxMessage::query()->where('eml_sha256', $sha256)->first();

            if ($existing instanceof InboxMessage) {
                // The same bytes again (a retried delivery): nothing new.
                return $existing;
            }

            try {
                WorkQuota::take($company, 'inbox_mail', 'mail');
            } catch (ValidationException) {
                throw new InboxQuotaExceeded;
            }

            $mail ??= $this->parser->parse($raw);
            $auth = MailAuthentication::evaluate($mail, $channel, $transport, $channel === Channel::Imap ? (string) config('services.inbox.imap_authserv_id') : null);
            $id = Str::uuid()->toString();
            $directory = "inkorg/{$company->id}/{$id}";
            $emlPath = "{$directory}/original.eml";
            Storage::disk(self::DISK)->put($emlPath, $raw);
            $attachments = $this->storeAttachments($mail, $directory);
            $reasons = $this->quarantineReasons($mail, $auth, $attachments);

            $message = DB::transaction(function () use ($id, $channel, $recipient, $alias, $mail, $sha256, $raw, $emlPath, $auth, $reasons, $attachments): InboxMessage {
                $message = new InboxMessage;
                $message->id = $id;
                $message->fill([
                    'channel' => $channel,
                    'recipient' => mb_substr($recipient, 0, 255),
                    'alias' => $alias,
                    'from_email' => mb_substr($mail->fromEmail, 0, 255),
                    'from_name' => $mail->fromName,
                    'subject' => $mail->subject,
                    'message_id' => $mail->messageId === null ? null : mb_substr($mail->messageId, 0, 998),
                    'received_at' => now(),
                    'eml_path' => $emlPath,
                    'eml_sha256' => $sha256,
                    'eml_size' => strlen($raw),
                    'body_text' => $mail->text === '' ? null : $mail->text,
                    'auth' => $auth,
                    'status' => $reasons === [] ? MessageStatus::NeedsReview : MessageStatus::Quarantine,
                    'quarantine_reasons' => $reasons,
                    'warnings' => InboxChecks::lookalike($mail->fromEmail),
                ])->save();

                foreach ($attachments as $position => $attachment) {
                    InboxAttachment::create([...$attachment, 'inbox_message_id' => $message->id, 'position' => $position + 1]);
                }

                return $message;
            });

            Audit::record('inbox.received', $message, [
                'channel' => $channel->value,
                'from' => $message->from_email,
                'sha256' => $sha256,
                'size' => $message->eml_size,
                'attachments' => count($attachments),
                'quarantine' => array_column($reasons, 'code'),
                'auth' => $auth,
            ], $company);

            if ($reasons === []) {
                InterpretInboxMessageJob::dispatch($company->id, $message->id);
            } else {
                app(NotifyInbox::class)($company);
            }

            return $message->refresh();
        });
    }

    /**
     * @return list<array{filename: string, mime: string, size: int, sha256: string, path: string, blocked_reason: ?string}>
     */
    private function storeAttachments(ParsedMail $mail, string $directory): array
    {
        $stored = [];

        foreach ($mail->attachments as $position => $attachment) {
            $contents = $attachment['contents'];
            $filename = FileName::clean($attachment['filename'] !== '' ? $attachment['filename'] : __('bilaga-:number', ['number' => $position + 1]));
            $blocked = $this->screen->reason($filename, $contents);

            $path = "{$directory}/".($position + 1).'.'.AttachmentType::storedExtension($filename);
            Storage::disk(self::DISK)->put($path, $contents);

            $stored[] = [
                'filename' => mb_substr($filename, 0, 255),
                'mime' => mb_substr(AttachmentType::detect($contents), 0, 255),
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'path' => $path,
                'blocked_reason' => $blocked,
            ];
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $auth
     * @param  list<array{filename: string, blocked_reason: ?string}>  $attachments
     * @return list<array{code: string, message: string}>
     */
    private function quarantineReasons(ParsedMail $mail, array $auth, array $attachments): array
    {
        $reasons = [];

        if ($mail->fromEmail === '' || filter_var($mail->fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            $reasons[] = ['code' => 'NO_SENDER', 'message' => __('Meddelandet saknar en giltig avsändaradress.')];
        }

        if ((bool) $auth['failed']) {
            $reasons[] = ['code' => 'AUTH_FAILED', 'message' => MailAuthentication::explain($auth)];
        }

        if ((bool) $auth['spam']) {
            $reasons[] = ['code' => 'SPAM', 'message' => __('Mottagande server märkte meddelandet som skräppost.')];
        }

        foreach ($attachments as $attachment) {
            if ($attachment['blocked_reason'] !== null) {
                $reasons[] = ['code' => 'BLOCKED_ATTACHMENT', 'message' => __(':name: :reason', ['name' => $attachment['filename'], 'reason' => $attachment['blocked_reason']])];
            }
        }

        if ($mail->fromEmail !== '' && ! InboxChecks::isAllowed($mail->fromEmail)) {
            $reasons[] = ['code' => 'NOT_ALLOWLISTED', 'message' => __(':sender finns inte på listan över godkända avsändare. Inget tolkas förrän avsändaren har godkänts.', ['sender' => $mail->fromEmail])];
        }

        return $reasons;
    }
}

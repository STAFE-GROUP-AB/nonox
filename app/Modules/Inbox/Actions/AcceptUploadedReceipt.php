<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Jobs\InterpretInboxMessageJob;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\AttachmentScreen;
use App\Modules\Inbox\Support\AttachmentType;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\FileName;
use App\Support\Tenancy\CurrentCompany;
use App\Support\WorkQuota;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inkorg → Fota kvitto (ADR-072 §8): a company receipt photographed on the
 * phone by a signed-in person with inbox:manage. It becomes an inbox
 * message from that person, of type receipt, and passes the same checks
 * as a mail attachment before anything reads it (ADR-023 "Security before
 * any reading"): kept byte for byte with its SHA-256, dangerous types
 * blocked and the virus scan when one is configured. A blocked file waits
 * in quarantine. Then the same interpretation (template → NONOX AI when
 * allowed → manual queue) and the same approval; nothing is booked before a
 * person approves (KJ-R21).
 *
 * The person signed in is the sender, so there is no allowlist or mail
 * authentication to check. Nothing here tells the user the paper receipt
 * may be thrown away (KJ-R22, L14 open).
 */
class AcceptUploadedReceipt
{
    public const array TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];

    public function __construct(private readonly ModuleManager $modules, private readonly AttachmentScreen $screen) {}

    public function __invoke(User $user, Company $company, UploadedFile $file): InboxMessage
    {
        $this->modules->ensureEnabled(InboxModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'inbox:manage')) {
            throw new AuthorizationException;
        }

        Validator::make(['receipt' => $file], [
            'receipt' => ['required', 'file', 'max:10240', 'mimes:'.implode(',', self::TYPES)],
        ], [
            'receipt.required' => __('Ta en bild av kvittot eller välj en PDF.'),
            'receipt.mimes' => __('Kvittot ska vara en bild eller en PDF.'),
            'receipt.max' => __('Filen är för stor. Den får vara högst 10 MB.'),
        ])->validate();

        $contents = (string) file_get_contents((string) $file->getRealPath());
        $sha256 = hash('sha256', $contents);
        $filename = FileName::clean($file->getClientOriginalName() !== '' ? $file->getClientOriginalName() : 'kvitto.jpg');

        return CurrentCompany::run($company, function () use ($user, $company, $contents, $sha256, $filename): InboxMessage {
            $existing = InboxMessage::query()->where('eml_sha256', $sha256)->first();

            if ($existing instanceof InboxMessage) {
                throw ValidationException::withMessages(['receipt' => __('Kvittot finns redan i inkorgen.')]);
            }

            try {
                WorkQuota::take($company, 'inbox_mail', 'mail');
            } catch (ValidationException) {
                throw new InboxQuotaExceeded;
            }

            $blocked = $this->screen->reason($filename, $contents);
            $id = Str::uuid()->toString();
            $path = "inkorg/{$company->id}/{$id}/1.".AttachmentType::storedExtension($filename);
            Storage::disk(AcceptInboundMail::DISK)->put($path, $contents);
            $reasons = $blocked === null ? [] : [['code' => 'BLOCKED_ATTACHMENT', 'message' => __(':name: :reason', ['name' => $filename, 'reason' => $blocked])]];

            $message = DB::transaction(function () use ($id, $user, $filename, $contents, $sha256, $path, $reasons, $blocked): InboxMessage {
                $message = new InboxMessage;
                $message->id = $id;
                $message->fill([
                    'channel' => Channel::Upload,
                    'recipient' => 'upload',
                    'alias' => 'kvitto',
                    'from_email' => mb_substr((string) $user->email, 0, 255),
                    'from_name' => mb_substr((string) $user->name, 0, 255),
                    'subject' => __('Kvitto fotat i NONOX'),
                    'message_id' => null,
                    'received_at' => now(),
                    'eml_path' => $path,
                    'eml_sha256' => $sha256,
                    'eml_size' => strlen($contents),
                    'body_text' => null,
                    'auth' => ['channel' => 'upload', 'user_id' => $user->id, 'failed' => false, 'spam' => false],
                    'status' => $reasons === [] ? MessageStatus::NeedsReview : MessageStatus::Quarantine,
                    'quarantine_reasons' => $reasons,
                    'warnings' => [],
                ])->save();

                InboxAttachment::create([
                    'inbox_message_id' => $message->id,
                    'position' => 1,
                    'filename' => mb_substr($filename, 0, 255),
                    'mime' => mb_substr(AttachmentType::detect($contents), 0, 255),
                    'size' => strlen($contents),
                    'sha256' => $sha256,
                    'path' => $path,
                    'blocked_reason' => $blocked,
                ]);

                return $message;
            });

            Audit::record('inbox.received', $message, ['channel' => Channel::Upload->value, 'sha256' => $sha256, 'size' => strlen($contents), 'attachments' => 1, 'quarantine' => array_column($reasons, 'code')], $company, $user);

            if ($reasons === []) {
                InterpretInboxMessageJob::dispatch($company->id, $message->id);
            } else {
                app(NotifyInbox::class)($company);
            }

            return $message->refresh();
        });
    }
}

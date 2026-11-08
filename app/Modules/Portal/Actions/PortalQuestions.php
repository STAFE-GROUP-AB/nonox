<?php

declare(strict_types=1);

namespace App\Modules\Portal\Actions;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\Inbox\Support\AttachmentScreen;
use App\Modules\ModuleManager;
use App\Modules\Portal\Domain\ContactStatus;
use App\Modules\Portal\Domain\ThreadKind;
use App\Modules\Portal\Mail\PortalMail;
use App\Modules\Portal\Models\PortalAttachment;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalMessage;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\Notifications\PortalQuestionNotice;
use App\Modules\Portal\PortalModule;
use App\Modules\Portal\Support\PortalContext;
use App\Modules\Portal\Support\PortalRecipients;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use finfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Questions between a customer's contact and the company (ADR-075 §6):
 * threads on an invoice, a quote or nothing, and disputes of an invoice
 * ("Jag bestrider fakturan"). Kept, not the chat. Message bodies are plain
 * text of at most 4 000 characters; attachments are PDF, JPEG or PNG up to
 * 10 MB, five per message, checked by content and through the inbox's
 * screen (dangerous content, virus scanner when configured), stored on the
 * private disk.
 *
 * A contact's message creates a notice for the users with
 * customer-portal:reply; the company's reply e-mails the contact a link,
 * never the content. The audit log gets ids only, never text.
 */
final class PortalQuestions
{
    /** @var list<string> files written in the current transaction */
    private array $written = [];

    public function __construct(
        private readonly AttachmentScreen $screen,
        private readonly ModuleManager $modules,
    ) {}

    /**
     * A new thread from the contact in context.
     *
     * @param  array<string, mixed>  $input  subject, body, kind (QUESTION|DISPUTE)
     * @param  list<UploadedFile>  $files
     */
    public function open(PortalContext $context, array $input, ?Invoice $invoice, ?string $quoteId, array $files): PortalThread
    {
        $validated = Validator::make($input, [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:'.PortalMessage::MAX_LENGTH],
            'kind' => ['required', 'in:QUESTION,DISPUTE'],
        ], [], ['subject' => __('rubrik'), 'body' => __('meddelande')])->validate();

        $kind = ThreadKind::from((string) $validated['kind']);

        if ($kind === ThreadKind::Dispute && ! $invoice instanceof Invoice) {
            throw ValidationException::withMessages(['kind' => __('Bara en faktura kan bestridas.')]);
        }

        $stored = $this->screen($files);
        $contact = $context->contact();

        $thread = $this->writing(function () use ($contact, $validated, $kind, $invoice, $quoteId, $stored): PortalThread {
            $thread = PortalThread::query()->create([
                'customer_id' => $contact->customer_id,
                'contact_id' => $contact->id,
                'subject_type' => $invoice instanceof Invoice ? PortalThread::INVOICE : ($quoteId !== null ? PortalThread::QUOTE : null),
                'subject_id' => $invoice instanceof Invoice ? $invoice->id : $quoteId,
                'subject' => trim((string) $validated['subject']),
                'kind' => $kind,
                'last_message_at' => now(),
            ]);

            $this->message($thread, $contact, null, (string) $validated['body'], $stored);

            Audit::record($kind === ThreadKind::Dispute ? 'customer_portal.dispute_opened' : 'customer_portal.thread_opened', $thread, ['thread_id' => $thread->id, 'contact_id' => $contact->id, 'customer_id' => $contact->customer_id, 'subject_type' => $thread->subject_type, 'subject_id' => $thread->subject_id]);

            return $thread;
        });

        $this->notifyCompany($context->company(), $thread);

        return $thread;
    }

    /**
     * A message from the contact in context, in one of the customer's threads.
     *
     * @param  list<UploadedFile>  $files
     */
    public function post(PortalContext $context, PortalThread $thread, string $body, array $files): PortalMessage
    {
        $this->validateBody($body);
        $stored = $this->screen($files);
        $contact = $context->contact();

        $message = $this->writing(function () use ($thread, $contact, $body, $stored): PortalMessage {
            $locked = PortalThread::query()->whereKey($thread->id)->where('customer_id', $contact->customer_id)->lockForUpdate()->firstOrFail();
            $message = $this->message($locked, $contact, null, $body, $stored);
            // A new message opens a closed thread again.
            $locked->forceFill(['status' => PortalThread::OPEN, 'closed_at' => null, 'answered' => false, 'last_message_at' => now()])->save();

            return $message;
        });

        $this->notifyCompany($context->company(), $thread);

        return $message;
    }

    /**
     * The company's reply (customer-portal:reply).
     *
     * @param  list<UploadedFile>  $files
     */
    public function reply(User $user, Company $company, PortalThread $thread, string $body, array $files = []): PortalMessage
    {
        $this->authorize($user, $company, 'customer-portal:reply');
        $this->validateBody($body);
        $stored = $this->screen($files);

        return CurrentCompany::run($company, function () use ($user, $company, $thread, $body, $stored): PortalMessage {
            $message = $this->writing(function () use ($user, $thread, $body, $stored): PortalMessage {
                $locked = PortalThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
                $message = $this->message($locked, null, $user, $body, $stored);
                $locked->forceFill(['answered' => true, 'last_message_at' => now()])->save();

                return $message;
            });

            $contact = $thread->contact_id === null ? null : PortalContact::query()->find($thread->contact_id);
            $identity = $contact === null ? null : PortalIdentity::query()->find($contact->portal_identity_id);

            // A link only, never the text: nothing about the invoice sits in an outside mailbox.
            if ($contact instanceof PortalContact && $contact->status === ContactStatus::Active && $identity instanceof PortalIdentity && ! $company->isTestCompany()) {
                $url = route('portal.questions.show', ['portalKey' => PortalSettings::current()->portal_key, 'thread' => $thread->id]);
                Mail::to($identity->email)->queue((new PortalMail(PortalMail::REPLY, $company->id, $url))->afterCommit());
            }

            return $message;
        });
    }

    public function close(User $user, Company $company, PortalThread $thread, bool $close = true): void
    {
        $this->authorize($user, $company, 'customer-portal:reply');

        CurrentCompany::run($company, function () use ($user, $thread, $close): void {
            $thread->forceFill(['status' => $close ? PortalThread::CLOSED : PortalThread::OPEN, 'closed_at' => $close ? now() : null])->save();
            Audit::record($close ? 'customer_portal.thread_closed' : 'customer_portal.thread_reopened', $thread, ['thread_id' => $thread->id], null, $user);
        });
    }

    /** "Spara": a closed thread the company keeps is not pruned after 24 months. */
    public function keep(User $user, Company $company, PortalThread $thread, bool $keep): void
    {
        $this->authorize($user, $company, 'customer-portal:reply');

        CurrentCompany::run($company, function () use ($user, $thread, $keep): void {
            $thread->forceFill(['keep' => $keep])->save();
            Audit::record('customer_portal.thread_kept', $thread, ['thread_id' => $thread->id, 'keep' => $keep], null, $user);
        });
    }

    /**
     * @param  list<array{name: string, mime: string, contents: string}>  $files  already checked by screen()
     */
    private function message(PortalThread $thread, ?PortalContact $contact, ?User $user, string $body, array $files): PortalMessage
    {
        $message = PortalMessage::query()->create([
            'thread_id' => $thread->id,
            'contact_id' => $contact?->id,
            'user_id' => $user?->id,
            'author_kind' => $contact instanceof PortalContact ? PortalMessage::CONTACT : PortalMessage::USER,
            'body' => trim(str_replace("\r\n", "\n", $body)),
        ]);

        foreach ($files as $file) {
            $path = 'portal/'.$thread->company_id.'/'.Str::uuid()->toString().'.'.PortalAttachment::MIMES[$file['mime']];
            Storage::disk(PortalAttachment::DISK)->put($path, $file['contents']);
            $this->written[] = $path;

            PortalAttachment::query()->create(['message_id' => $message->id, 'path' => $path, 'original_name' => $file['name'], 'mime' => $file['mime'], 'size' => strlen($file['contents']), 'sha256' => hash('sha256', $file['contents'])]);
        }

        return $message;
    }

    /**
     * Runs the writes in one transaction; the files written inside it are
     * deleted again if it fails, so no file is left without its row.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function writing(Closure $callback): mixed
    {
        $this->written = [];

        try {
            return DB::transaction($callback);
        } catch (Throwable $exception) {
            Storage::disk(PortalAttachment::DISK)->delete($this->written);

            throw $exception;
        } finally {
            $this->written = [];
        }
    }

    private function validateBody(string $body): void
    {
        Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:'.PortalMessage::MAX_LENGTH]], [], ['body' => __('meddelande')])->validate();
    }

    /**
     * Checks every file before anything is written: at most five, each a
     * PDF, JPEG or PNG by its content, at most 10 MB, and through the
     * inbox's screen.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{name: string, mime: string, contents: string}>
     */
    private function screen(array $files): array
    {
        if (count($files) > PortalAttachment::MAX_PER_MESSAGE) {
            throw ValidationException::withMessages(['files' => __('Högst :count filer per meddelande.', ['count' => PortalAttachment::MAX_PER_MESSAGE])]);
        }

        $checked = [];

        foreach ($files as $file) {
            $name = mb_substr(basename($file->getClientOriginalName()), 0, 200);
            $size = $file->getSize();

            if ($size === false || $size > PortalAttachment::MAX_BYTES || $size === 0) {
                throw ValidationException::withMessages(['files' => __(':name är större än 10 MB eller tom.', ['name' => $name])]);
            }

            $contents = (string) file_get_contents($file->getRealPath());
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';

            if (strlen($contents) > PortalAttachment::MAX_BYTES || $contents === '') {
                throw ValidationException::withMessages(['files' => __(':name är större än 10 MB eller tom.', ['name' => $name])]);
            }

            if (! array_key_exists($mime, PortalAttachment::MIMES)) {
                throw ValidationException::withMessages(['files' => __(':name är inte en PDF, JPEG eller PNG.', ['name' => $name])]);
            }

            $reason = $this->screen->reason($name, $contents);

            if ($reason !== null) {
                throw ValidationException::withMessages(['files' => $reason]);
            }

            $checked[] = ['name' => $name, 'mime' => $mime, 'contents' => $contents];
        }

        return $checked;
    }

    private function notifyCompany(Company $company, PortalThread $thread): void
    {
        foreach (PortalRecipients::withPermission($company, 'customer-portal:reply') as $user) {
            $user->notify(new PortalQuestionNotice($company->id, (string) ($company->legal_name ?: $company->name), $thread->kind === ThreadKind::Dispute ? __('En kund bestrider en faktura i kundportalen.') : __('En kund har skrivit i kundportalen.')));
        }
    }

    /** @throws AuthorizationException */
    private function authorize(User $user, Company $company, string $permission): void
    {
        abort_unless($this->modules->isEnabled(PortalModule::KEY, $company), 404);

        if (! $user->hasTeamPermission($company, $permission)) {
            throw new AuthorizationException;
        }
    }
}

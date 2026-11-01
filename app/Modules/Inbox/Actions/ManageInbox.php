<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\AllowedSender;
use App\Modules\Inbox\Models\InboxMailbox;
use App\Modules\Inbox\Models\InboxTemplate;
use App\Modules\Inbox\Support\Imap\ImapHost;
use App\Modules\Inbox\Support\Imap\MailboxSettings;
use App\Modules\Inbox\Support\Imap\UnsafeImapHost;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The inbox's settings: the allowlist, templates, the address and the
 * IMAP mailbox. Every change is audited; the IMAP password is stored
 * encrypted and never written to the audit log.
 */
class ManageInbox
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ImapHost $hosts,
    ) {}

    public function allow(User $user, Company $company, string $input, string $note = ''): AllowedSender
    {
        $this->authorize($user, $company, 'inbox:settings');
        $pattern = AllowedSender::normalise($input)
            ?? throw ValidationException::withMessages(['pattern' => __('Skriv en e-postadress, till exempel kassa@kassasystem.se, eller en hel domän, till exempel @kassasystem.se.')]);

        return CurrentCompany::run($company, function () use ($user, $company, $pattern, $note): AllowedSender {
            if (AllowedSender::query()->where('pattern', $pattern)->exists()) {
                throw ValidationException::withMessages(['pattern' => __(':pattern finns redan på listan.', ['pattern' => $pattern])]);
            }

            $sender = AllowedSender::create(['pattern' => $pattern, 'note' => trim($note) === '' ? null : mb_substr(trim($note), 0, 255), 'created_by' => $user->id]);
            Audit::record('inbox.sender_allowed', $sender, ['pattern' => $pattern], $company, $user);

            return $sender;
        });
    }

    public function disallow(User $user, Company $company, int $senderId): void
    {
        $this->authorize($user, $company, 'inbox:settings');

        CurrentCompany::run($company, function () use ($user, $company, $senderId): void {
            $sender = AllowedSender::query()->findOrFail($senderId);
            $sender->delete();
            Audit::record('inbox.sender_removed', $sender, ['pattern' => $sender->pattern], $company, $user);
        });
    }

    /**
     * Turns automatic booking on or off for a template. When on, the
     * template books in the name of the person who turned it on, and only
     * while that person may post.
     */
    public function setAutoBook(User $user, Company $company, int $templateId, bool $on): InboxTemplate
    {
        $this->authorize($user, $company, 'inbox:settings');

        return CurrentCompany::run($company, function () use ($user, $company, $templateId, $on): InboxTemplate {
            $template = InboxTemplate::query()->findOrFail($templateId);
            $permission = $template->kind === InboxTemplate::SUPPLIER_INVOICE ? 'supplier-invoices:create' : 'vouchers:post';

            if ($on && ! $user->hasTeamPermission($company, $permission)) {
                throw ValidationException::withMessages(['template' => __('Du behöver själv få bokföra för att slå på automatisk bokföring.')]);
            }

            $template->forceFill(['auto_book' => $on, 'auto_book_user_id' => $on ? $user->id : null])->save();
            Audit::record('inbox.template_auto_book', $template, ['auto_book' => $on, 'sender' => $template->sender], $company, $user);

            return $template;
        });
    }

    public function deleteTemplate(User $user, Company $company, int $templateId): void
    {
        $this->authorize($user, $company, 'inbox:settings');

        CurrentCompany::run($company, function () use ($user, $company, $templateId): void {
            $template = InboxTemplate::query()->findOrFail($templateId);
            $template->delete();
            Audit::record('inbox.template_deleted', $template, ['sender' => $template->sender, 'kind' => $template->kind], $company, $user);
        });
    }

    /**
     * Gives the company a new address. The old one stops working at once:
     * mail to it is dropped like mail to any unknown address. Only the owner
     * can do this.
     */
    public function regenerateAddress(User $user, Company $company): InboxMailbox
    {
        if (! $user->ownsTeam($company)) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, function () use ($user, $company): InboxMailbox {
            $mailbox = InboxAddress::mailbox($company);
            $before = $mailbox->slug;
            $mailbox->forceFill(['slug' => InboxAddress::newSlug($company), 'slug_changed_at' => now()])->save();
            Audit::record('inbox.address_changed', $mailbox, ['before' => InboxAddress::forSlug($before), 'after' => InboxAddress::forSlug($mailbox->slug)], $company, $user);

            return $mailbox;
        });
    }

    /**
     * @param  array<string, mixed>  $input  imap_enabled, imap_host, imap_port, imap_encryption, imap_username, imap_password (empty keeps the stored one), imap_folder
     */
    public function saveImap(User $user, Company $company, array $input): void
    {
        $this->authorize($user, $company, 'inbox:settings');

        $validated = Validator::make($input, [
            'imap_enabled' => ['boolean'],
            'imap_host' => ['required_if:imap_enabled,true', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'imap_port' => ['required_if:imap_enabled,true', 'nullable', 'integer', Rule::in(ImapHost::PORTS)],
            'imap_encryption' => ['required', Rule::in(MailboxSettings::ENCRYPTIONS)],
            'imap_username' => ['required_if:imap_enabled,true', 'nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:500'],
            'imap_folder' => ['nullable', 'string', 'max:255'],
        ], [
            'imap_host.regex' => __('Skriv serverns namn, till exempel imap.exempel.se.'),
            'imap_port.in' => __('NONOX ansluter bara till port 993 (TLS) eller 143 (STARTTLS).'),
            'imap_encryption.in' => __('Välj SSL/TLS eller STARTTLS. E-post hämtas aldrig okrypterat.'),
        ], [
            'imap_host' => __('server'),
            'imap_port' => __('port'),
            'imap_username' => __('användarnamn'),
        ])->validate();

        $stored = $this->modules->settings(InboxModule::KEY, $company);
        $secret = filled($validated['imap_password'] ?? null) ? Crypt::encryptString((string) $validated['imap_password']) : ($stored['imap_secret'] ?? null);

        if ((bool) ($validated['imap_enabled'] ?? false) && blank($secret)) {
            throw ValidationException::withMessages(['imap_password' => __('Skriv lösenordet till brevlådan.')]);
        }

        if (filled($validated['imap_host'] ?? null)) {
            try {
                $this->hosts->vet((string) $validated['imap_host']);
            } catch (UnsafeImapHost $exception) {
                throw ValidationException::withMessages(['imap_host' => $exception->explain()]);
            }
        }

        $this->modules->updateSettings(InboxModule::KEY, $company, $user, [
            'imap_enabled' => (bool) ($validated['imap_enabled'] ?? false),
            'imap_host' => $validated['imap_host'] ?? null,
            'imap_port' => isset($validated['imap_port']) ? (int) $validated['imap_port'] : 993,
            'imap_encryption' => $validated['imap_encryption'],
            'imap_username' => $validated['imap_username'] ?? null,
            'imap_secret' => $secret,
            'imap_folder' => filled($validated['imap_folder'] ?? null) ? $validated['imap_folder'] : 'INBOX',
        ]);
    }

    /**
     * @param  array{ai_interpretation: bool, daily_summary: bool, notify_on_action: bool}  $options
     */
    public function saveOptions(User $user, Company $company, array $options): void
    {
        $this->authorize($user, $company, 'inbox:settings');
        $this->modules->updateSettings(InboxModule::KEY, $company, $user, [
            'ai_interpretation' => (bool) $options['ai_interpretation'],
            'daily_summary' => (bool) $options['daily_summary'],
            'notify_on_action' => (bool) $options['notify_on_action'],
        ]);
    }

    private function authorize(User $user, Company $company, string $permission): void
    {
        if (! $user->hasTeamPermission($company, $permission)) {
            throw new AuthorizationException;
        }
    }
}

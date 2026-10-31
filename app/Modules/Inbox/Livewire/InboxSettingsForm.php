<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\Actions\ManageInbox;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\AllowedSender;
use App\Modules\Inbox\Models\InboxTemplate;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\Inbox\Support\InboxSettings;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The inbox's settings under Moduler: the address with its aliases and the
 * shared address, the allowlist of senders, IMAP for a self-hosted
 * installation, the learned templates and notifications.
 */
class InboxSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'inbox.settings-form';

    #[Locked]
    public int $companyId;

    public string $newPattern = '';

    public string $newNote = '';

    public bool $aiInterpretation = true;

    public bool $dailySummary = false;

    public bool $notifyOnAction = false;

    public bool $imapEnabled = false;

    public string $imapHost = '';

    public string $imapPort = '993';

    public string $imapEncryption = 'ssl';

    public string $imapUsername = '';

    public string $imapPassword = '';

    public string $imapFolder = 'INBOX';

    public bool $confirmRegenerate = false;

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'inbox:settings'), 403);

        $this->companyId = $company->id;
        $options = InboxSettings::for($company);
        $settings = app(ModuleManager::class)->settings(InboxModule::KEY, $company);
        $this->aiInterpretation = $options['ai_interpretation'];
        $this->dailySummary = $options['daily_summary'];
        $this->notifyOnAction = $options['notify_on_action'];
        $this->imapEnabled = $options['imap_enabled'];
        $this->imapHost = (string) ($settings['imap_host'] ?? '');
        $this->imapPort = (string) ($settings['imap_port'] ?? '993');
        $this->imapEncryption = (string) ($settings['imap_encryption'] ?? 'ssl');
        $this->imapUsername = (string) ($settings['imap_username'] ?? '');
        $this->imapFolder = (string) ($settings['imap_folder'] ?? 'INBOX');
    }

    public function addSender(ManageInbox $manage): void
    {
        $sender = $manage->allow($this->user(), $this->company(), $this->newPattern, $this->newNote);
        $this->reset('newPattern', 'newNote');
        $this->toast(__(':pattern får nu skicka till inkorgen.', ['pattern' => $sender->pattern]));
    }

    public function removeSender(ManageInbox $manage, int $senderId): void
    {
        $manage->disallow($this->user(), $this->company(), $senderId);
        $this->toast(__('Avsändaren är borttagen från listan.'));
    }

    public function saveOptions(ManageInbox $manage): void
    {
        $manage->saveOptions($this->user(), $this->company(), [
            'ai_interpretation' => $this->aiInterpretation,
            'daily_summary' => $this->dailySummary,
            'notify_on_action' => $this->notifyOnAction,
        ]);
        $this->toast(__('Inställningarna för inkorgen är sparade.'));
    }

    public function saveImap(ManageInbox $manage): void
    {
        $manage->saveImap($this->user(), $this->company(), [
            'imap_enabled' => $this->imapEnabled,
            'imap_host' => trim($this->imapHost),
            'imap_port' => trim($this->imapPort) === '' ? null : trim($this->imapPort),
            'imap_encryption' => $this->imapEncryption,
            'imap_username' => trim($this->imapUsername),
            'imap_password' => $this->imapPassword,
            'imap_folder' => trim($this->imapFolder),
        ]);
        $this->imapPassword = '';
        $this->toast($this->imapEnabled ? __('Brevlådan är sparad. NONOX hämtar ny e-post med några minuters mellanrum.') : __('Hämtning från brevlådan är avstängd.'));
    }

    public function toggleAutoBook(ManageInbox $manage, int $templateId): void
    {
        $template = CurrentCompany::run($this->companyId, fn (): InboxTemplate => InboxTemplate::query()->findOrFail($templateId));
        $template = $manage->setAutoBook($this->user(), $this->company(), $templateId, ! $template->auto_book);
        $this->toast($template->auto_book
            ? __('Automatisk bokföring är påslagen för mallen. Den bokför bara när underlaget stämmer exakt och inget behöver kontrolleras.')
            : __('Automatisk bokföring är avstängd för mallen.'));
    }

    public function deleteTemplate(ManageInbox $manage, int $templateId): void
    {
        $manage->deleteTemplate($this->user(), $this->company(), $templateId);
        $this->toast(__('Mallen är borttagen. Nästa underlag från avsändaren tolkas på nytt.'));
    }

    public function regenerate(ManageInbox $manage): void
    {
        if (! $this->confirmRegenerate) {
            $this->confirmRegenerate = true;

            return;
        }

        $mailbox = $manage->regenerateAddress($this->user(), $this->company());
        $this->confirmRegenerate = false;
        $this->toast(__('Företaget har fått en ny adress: :address. Den gamla tar inte längre emot något.', ['address' => InboxAddress::forSlug($mailbox->slug)]));
    }

    public function render(): View
    {
        $company = $this->company();

        return CurrentCompany::run($company, fn (): View => view('inbox::livewire.settings-form', [
            'mailbox' => InboxAddress::mailbox($company),
            'shared' => InboxAddress::shared(),
            'aliases' => InboxAddress::ALIASES,
            'senders' => AllowedSender::query()->orderBy('pattern')->get(),
            'templates' => InboxTemplate::query()->with('autoBookUser')->orderBy('sender')->get(),
            'hasPassword' => filled(app(ModuleManager::class)->settings(InboxModule::KEY, $company)['imap_secret'] ?? null),
            'isOwner' => $this->user()->ownsTeam($company),
        ]));
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}

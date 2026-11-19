<?php

declare(strict_types=1);

namespace App\Modules\Signing\Livewire;

use App\Domain\Signing\SigningDeadlines;
use App\Domain\Signing\Verification\SignerCheck;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\SigningEnvelope;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Signering → Mallar → Ny mall / Ändra mall (ADR-046 amendment, gap 8,
 * package S14): the name, the roles (name, step, check, and whether the
 * role is the company's own), the last day, the reminders, the copy
 * recipients, the language and, for a document of the company's own,
 * whether it is confidential. A template for a quote or an agreement is
 * made here; one for a document of the company's own is made in the send
 * wizard (it needs the PDF and the fields), and its fields are changed
 * there too ("Ändra fält" opens the wizard with the template applied).
 */
class TemplatePage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    #[Locked]
    public ?string $templateId = null;

    #[Locked]
    public string $type = SigningTemplate::QUOTE;

    public string $name = '';

    /** @var list<array{key: ?string, label: string, step: int|string, verification: string, internal: bool}> */
    public array $roles = [];

    public string $deadlineDays = '';

    public bool $autoReminders = true;

    public string $reminderDays = '3';

    public string $language = 'sv';

    public bool $confidential = false;

    /** @var list<array{email: string, name: string, language: string}> */
    public array $copies = [];

    public function mount(?string $templateId = null): void
    {
        abort_unless($this->allowed(SigningModule::SEND), 404);

        if ($templateId === null) {
            $this->roles = [['key' => null, 'label' => '', 'step' => 1, 'verification' => SignerCheck::None->value, 'internal' => false]];

            return;
        }

        abort_unless(Str::isUuid($templateId), 404);
        $template = SigningTemplate::query()->with('roles')->findOrFail($templateId);

        try {
            app(ManageSigningTemplates::class)->authorizeEdit($this->user(), $this->company(), $template);
        } catch (AuthorizationException) {
            abort(404);
        }

        $this->templateId = $template->id;
        $this->type = $template->document_type;
        $this->name = $template->name;
        $this->roles = array_values($template->roles->map(fn ($role): array => ['key' => $role->key, 'label' => $role->label, 'step' => $role->step, 'verification' => $role->verification, 'internal' => $role->internal])->all());
        $this->deadlineDays = (string) ($template->deadline_days ?? '');
        $this->autoReminders = $template->auto_reminders;
        $this->reminderDays = $template->reminder_days === null ? 'single' : (string) $template->reminder_days;
        $this->language = $template->language;
        $this->confidential = $template->confidential;
        $this->copies = array_map(fn (array $copy): array => ['email' => (string) $copy['email'], 'name' => (string) ($copy['name'] ?? ''), 'language' => (string) $copy['language']], (array) ($template->copies ?? []));
    }

    public function setType(string $type): void
    {
        if ($this->templateId === null && in_array($type, [SigningTemplate::QUOTE, SigningTemplate::AGREEMENT], true)) {
            $this->type = $type;
        }
    }

    public function addRole(): void
    {
        if (count($this->roles) < SigningEnvelope::MAX_SIGNERS) {
            $last = $this->roles === [] ? 0 : (int) end($this->roles)['step'];
            $this->roles[] = ['key' => null, 'label' => '', 'step' => min(SigningEnvelope::MAX_STEP, max(1, $last + 1)), 'verification' => SignerCheck::None->value, 'internal' => false];
        }
    }

    public function removeRole(int $index): void
    {
        if (count($this->roles) > 1) {
            $this->roles = array_values(array_filter($this->roles, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
        }
    }

    public function addCopy(): void
    {
        if (count($this->copies) < SigningEnvelope::MAX_COPIES) {
            $this->copies[] = ['email' => '', 'name' => '', 'language' => 'sv'];
        }
    }

    public function removeCopy(int $index): void
    {
        $this->copies = array_values(array_filter($this->copies, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
    }

    /** "Ändra fält": a new document from the template's PDF, and on to the wizard, where "Uppdatera mallen" saves the changes. */
    public function editFields(ManageSigningTemplates $templates): void
    {
        abort_unless($this->templateId !== null, 404);

        try {
            $document = $templates->start($this->user(), $this->company(), SigningTemplate::query()->findOrFail($this->templateId));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->redirectRoute('signing.new', ['typ' => 'DOCUMENT', 'dokument' => $document->id, 'mall' => $this->templateId]);
    }

    public function save(ManageSigningTemplates $templates): void
    {
        $this->resetErrorBag();

        $input = [
            'name' => $this->name,
            'document_type' => $this->type,
            'roles' => $this->roles,
            'deadline_days' => $this->deadlineDays === '' ? null : $this->deadlineDays,
            'auto_reminders' => $this->autoReminders,
            'reminder_days' => $this->reminderDays === 'single' ? null : $this->reminderDays,
            'language' => $this->language,
            'confidential' => $this->type === SigningTemplate::DOCUMENT && $this->confidential,
            'copies' => $this->copies,
        ];

        try {
            if ($this->templateId === null) {
                $templates->create($this->user(), $this->company(), $input);
            } else {
                $templates->update($this->user(), $this->company(), SigningTemplate::query()->findOrFail($this->templateId), $input);
            }
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return;
        } catch (AuthorizationException) {
            $this->addError('name', __('Du får inte ändra den mallen.'));

            return;
        }

        Toast::flash($this->templateId === null ? __('Mallen är sparad.') : __('Mallen är uppdaterad.'));

        $this->redirectRoute('signing.templates');
    }

    public function render(): View
    {
        return view('signing-module::livewire.template', [
            'checks' => SignerCheck::choosable(),
            'intervals' => SigningDeadlines::INTERVALS,
            'minDays' => SigningDeadlines::MIN_DAYS,
            'maxDays' => SigningDeadlines::MAX_DAYS,
            'maxStep' => SigningEnvelope::MAX_STEP,
            'maxRoles' => SigningEnvelope::MAX_SIGNERS,
            'maxCopies' => SigningEnvelope::MAX_COPIES,
            'fileName' => $this->templateId === null ? null : SigningTemplate::query()->whereKey($this->templateId)->value('file_name'),
        ]);
    }
}

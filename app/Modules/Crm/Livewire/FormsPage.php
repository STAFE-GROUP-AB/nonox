<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Crm\Actions\SaveForm;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Form;
use App\Modules\Crm\Support\CompanyMembers;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Webbformulär (ADR-048): forms that create leads, with their address, an
 * iframe snippet for the company's own site and the number of
 * submissions. crm:settings.
 */
class FormsPage extends Component
{
    use ActsInCompany;
    use ShowsToasts;

    public ?int $editingId = null;

    public bool $open = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorizeSettings();
    }

    public function start(?int $id = null): void
    {
        $this->authorizeSettings();
        $model = $id === null ? null : Form::query()->findOrFail($id);
        $this->editingId = $model?->id;
        $this->form = [
            'name' => $model->name ?? __('Kontakta oss'),
            'fields' => $model->fields ?? ['phone' => 'optional', 'company' => 'optional', 'org_number' => 'off', 'message' => 'optional'],
            'consent_text' => $model->consent_text ?? Form::defaultNewsletterText($this->company()),
            'thank_you' => $model->thank_you ?? '',
            'embed_origins' => implode("\n", $model->embed_origins ?? []),
            'owner_id' => (string) ($model->owner_id ?? $this->user()->id),
            'tags' => $model->tags ?? '',
            'is_active' => $model->is_active ?? true,
        ];
        $this->open = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'open', 'form');
        $this->resetErrorBag();
    }

    public function save(SaveForm $save): void
    {
        $this->authorizeSettings();
        try {
            $model = $save($this->user(), $this->company(), $this->form, $this->editingId === null ? null : Form::query()->findOrFail($this->editingId));
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["form.{$key}" => $messages])->all());

            return;
        }

        $this->cancel();
        $this->toast(__('Formuläret :name är sparat.', ['name' => $model->name]));
    }

    public function delete(int $id, SaveForm $save): void
    {
        $this->authorizeSettings();

        try {
            $save->delete($this->user(), $this->company(), Form::query()->findOrFail($id));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->cancel();
        $this->toast(__('Formuläret är borttaget.'));
    }

    public function render(): View
    {
        return view('crm::livewire.forms', [
            'forms' => Form::query()->orderBy('name')->get(),
            'members' => CompanyMembers::all($this->company()),
            'labels' => ['phone' => __('Telefon'), 'company' => __('Företag'), 'org_number' => __('Organisationsnummer'), 'message' => __('Meddelande')],
            'states' => ['off' => __('Visas inte'), 'optional' => __('Frivilligt'), 'required' => __('Obligatoriskt')],
            'privacyUrl' => CrmSettings::privacyUrl($this->company()),
        ]);
    }

    /** The code a company pastes into its own site: the iframe, and a small optional script that fits its height. */
    public static function snippet(Form $form): string
    {
        $url = route('crm.forms.show', $form->token);
        $origin = rtrim((string) config('app.url'), '/');
        $title = e($form->name);

        return "<iframe src=\"{$url}\" title=\"{$title}\" style=\"width:100%;min-height:640px;border:0\" loading=\"lazy\"></iframe>\n"
            ."<script>addEventListener('message',function(e){if(e.origin==='{$origin}'&&e.data&&e.data.nonoxForm==='{$form->token}'){document.querySelectorAll('iframe[src=\"{$url}\"]').forEach(function(f){f.style.height=e.data.height+'px'})}})</script>";
    }

    private function authorizeSettings(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:settings'), 403);
    }
}

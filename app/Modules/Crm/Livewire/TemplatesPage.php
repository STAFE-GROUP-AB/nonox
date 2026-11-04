<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Crm\Actions\SaveEmailTemplate;
use App\Modules\Crm\Domain\MergeFields;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\EmailTemplate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Mejlmallar (ADR-048): the company's e-mail templates. Everyone with
 * crm:view sees them (sellers pick them on a card); crm:settings writes.
 */
class TemplatesPage extends Component
{
    use ActsInCompany;
    use ShowsToasts;

    public ?int $editingId = null;

    public bool $open = false;

    /** @var array<string, string> */
    public array $form = ['name' => '', 'subject' => '', 'body' => ''];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:view'), 403);
    }

    public function start(?int $id = null): void
    {
        $this->authorizeSettings();
        $template = $id === null ? null : EmailTemplate::query()->findOrFail($id);
        $this->editingId = $template?->id;
        $this->form = $template === null
            ? ['name' => '', 'subject' => '', 'body' => __("Hej {förnamn}!\n\n\n\nMed vänliga hälsningar\n{säljare}\n{mitt_företag}")]
            : $template->only(['name', 'subject', 'body']);
        $this->open = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'open', 'form');
        $this->resetErrorBag();
    }

    public function save(SaveEmailTemplate $save): void
    {
        $this->authorizeSettings();
        $template = $save($this->user(), $this->company(), $this->form, $this->editingId === null ? null : EmailTemplate::query()->findOrFail($this->editingId));
        $created = $this->editingId === null;
        $this->cancel();
        $this->toast($created ? __('Mallen :name är skapad.', ['name' => $template->name]) : __('Mallen :name är sparad.', ['name' => $template->name]));
    }

    public function delete(int $id, SaveEmailTemplate $save): void
    {
        $this->authorizeSettings();
        $save->delete($this->user(), $this->company(), EmailTemplate::query()->findOrFail($id));
        $this->cancel();
        $this->toast(__('Mallen är borttagen.'));
    }

    public function render(): View
    {
        return view('crm::livewire.templates', [
            'templates' => EmailTemplate::query()->orderBy('name')->get(),
            'canSettings' => $this->user()->hasTeamPermission($this->company(), 'crm:settings'),
            'fields' => MergeFields::help(),
        ]);
    }

    private function authorizeSettings(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:settings'), 403);
    }
}

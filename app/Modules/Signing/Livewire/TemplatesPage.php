<?php

declare(strict_types=1);

namespace App\Modules\Signing\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Signering → Mallar (ADR-046 amendment, gap 8, package S14): the company's
 * signing templates, active or archived, with the roles and the fields they
 * hold. "Använd" starts a send from a template with a file; a quote's or an
 * agreement's template is chosen in the send wizard that "Fler
 * undertecknare" opens. Whoever may send sees the templates; the creator
 * (and signing:manage) changes, archives and removes. A fixed number of
 * queries however many templates there are.
 */
class TemplatesPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;
    use WithPagination;

    public const int PER_PAGE = 25;

    #[Url(as: 'visa', except: 'aktiva')]
    public string $show = 'aktiva';

    public function mount(): void
    {
        abort_unless($this->allowed(SigningModule::SEND), 404);
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function archive(string $id, ManageSigningTemplates $templates): void
    {
        $this->act($id, fn (SigningTemplate $template) => $templates->archive($this->user(), $this->company(), $template), __('Mallen är arkiverad. Den går inte längre att välja, men signeringar som gjorts med den ändras inte.'));
    }

    public function restore(string $id, ManageSigningTemplates $templates): void
    {
        $this->act($id, fn (SigningTemplate $template) => $templates->restore($this->user(), $this->company(), $template), __('Mallen är återställd.'));
    }

    public function remove(string $id, ManageSigningTemplates $templates): void
    {
        $this->act($id, fn (SigningTemplate $template) => $templates->delete($this->user(), $this->company(), $template), __('Mallen är borttagen.'));
    }

    /** "Använd": a new document from the template's PDF, and on to the wizard with the roles and fields applied. */
    public function begin(string $id, ManageSigningTemplates $templates): void
    {
        abort_unless(Str::isUuid($id), 404);
        $template = SigningTemplate::query()->visibleTo($this->user(), $this->company())->findOrFail($id);

        try {
            $document = $templates->start($this->user(), $this->company(), $template);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->redirectRoute('signing.new', ['typ' => 'DOCUMENT', 'dokument' => $document->id, 'mall' => $template->id]);
    }

    public function render(): View
    {
        $show = $this->show === 'arkiverade' ? 'arkiverade' : 'aktiva';
        $user = $this->user();
        $company = $this->company();
        $manage = $this->allowed(SigningModule::MANAGE);

        $templates = SigningTemplate::query()
            ->visibleTo($user, $company)
            ->when($show === 'arkiverade', fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->withCount(['roles', 'fields'])
            ->with('creator:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);

        return view('signing-module::livewire.templates', [
            'templates' => $templates,
            'show' => $show,
            'canChange' => fn (SigningTemplate $template): bool => $manage || $template->created_by === $user->id,
            'company' => $company,
        ]);
    }

    /** @param  callable(SigningTemplate): mixed  $do */
    private function act(string $id, callable $do, string $done): void
    {
        abort_unless(Str::isUuid($id), 404);
        $template = SigningTemplate::query()->visibleTo($this->user(), $this->company())->findOrFail($id);

        try {
            $do($template);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        } catch (AuthorizationException) {
            $this->toast(__('Du får inte ändra den mallen.'), 'error');

            return;
        }

        $this->toast($done);
    }
}

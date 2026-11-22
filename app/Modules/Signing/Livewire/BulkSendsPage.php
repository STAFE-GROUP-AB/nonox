<?php

declare(strict_types=1);

namespace App\Modules\Signing\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Signing\Actions\ManageSigningBulkSends;
use App\Modules\Signing\Models\SigningBulkSend;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Signering → Massutskick (ADR-046 amendment, gap 8, package S15): the
 * company's bulk sends and the form that starts one: a template with a file
 * and a file with one row per envelope. "Läs in och granska" reads the file
 * once, deletes it and shows the preview; nothing is sent until a person
 * confirms there. signing:manage only. A fixed number of queries however
 * many runs there are.
 */
class BulkSendsPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;
    use WithFileUploads;
    use WithPagination;

    public const int PER_PAGE = 25;

    /** Kilobytes. 500 rows are far smaller; the file reader's own limit is 20 MB. */
    public const int MAX_KILOBYTES = 5_120;

    public string $templateId = '';

    public ?TemporaryUploadedFile $file = null;

    public function mount(): void
    {
        abort_unless($this->allowed(SigningModule::MANAGE), 404);
    }

    public function read(ManageSigningBulkSends $runs): void
    {
        abort_unless($this->allowed(SigningModule::MANAGE), 404);

        $this->validate([
            'templateId' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
        ], [
            'templateId.required' => __('Välj vilken mall som ska användas.'),
            'templateId.uuid' => __('Välj vilken mall som ska användas.'),
            'file.required' => __('Välj en fil med en rad per signering.'),
            'file.file' => __('Välj en fil med en rad per signering.'),
            'file.max' => __('Filen är för stor. Den får vara högst 5 MB.'),
        ]);

        $template = SigningTemplate::query()->visibleTo($this->user(), $this->company())->whereNull('archived_at')->where('document_type', SigningTemplate::DOCUMENT)->find($this->templateId);

        if (! $template instanceof SigningTemplate) {
            $this->addError('templateId', __('Välj vilken mall som ska användas.'));

            return;
        }

        try {
            $run = $runs->prepare($this->user(), $this->company(), $template, (string) $this->file?->getRealPath());
        } catch (ValidationException $exception) {
            $this->addError(array_key_first($exception->errors()) === 'template' ? 'templateId' : 'file', $this->firstError($exception));

            return;
        } catch (AuthorizationException) {
            abort(404);
        } finally {
            // The uploaded file is not kept: the rows needed are in the run, and nothing else.
            $this->file?->delete();
            $this->file = null;
        }

        $this->redirectRoute('signing.bulk-send', ['bulkSend' => $run->id]);
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();

        $templates = SigningTemplate::query()
            ->visibleTo($user, $company)
            ->whereNull('archived_at')
            ->where('document_type', SigningTemplate::DOCUMENT)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'name']);

        $runs = SigningBulkSend::query()
            ->with(['template:id,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('signing-module::livewire.bulk-sends', [
            'templates' => $templates,
            'runs' => $runs,
            'chosen' => Str::isUuid($this->templateId) ? $templates->firstWhere('id', $this->templateId) : null,
            'company' => $company,
        ]);
    }
}

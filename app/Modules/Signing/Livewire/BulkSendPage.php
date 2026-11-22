<?php

declare(strict_types=1);

namespace App\Modules\Signing\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Signing\Actions\ManageSigningBulkSends;
use App\Modules\Signing\Actions\ProcessSigningBulkSend;
use App\Modules\Signing\Models\SigningBulkSend;
use App\Modules\Signing\Models\SigningBulkSendRow;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Support\MailQuota;
use App\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Signering → Massutskick → one run (ADR-046 amendment, gap 8, package S15).
 * While the run is a preview it shows the first rows as they will be sent,
 * and every row's check; "Skicka" is the person's decision and queues the
 * envelopes. While it runs it shows the progress and polls; afterwards the
 * result list says, per row, whether an envelope was made (with a link to
 * it) or why not. The addresses are only shown in the preview: after the
 * send they are gone from the run. signing:manage only; another company's
 * run answers 404. A fixed number of queries however many rows there are.
 */
class BulkSendPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;
    use WithPagination;

    public const int PER_PAGE = 50;

    /** Rows shown with their people in the preview. */
    public const int PREVIEW_ROWS = 10;

    #[Locked]
    public string $bulkSendId = '';

    #[Url(as: 'visa', except: 'alla')]
    public string $show = 'alla';

    public function mount(string $bulkSend): void
    {
        abort_unless($this->allowed(SigningModule::MANAGE) && Str::isUuid($bulkSend), 404);
        SigningBulkSend::query()->findOrFail($bulkSend);
        $this->bulkSendId = $bulkSend;
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function confirm(ManageSigningBulkSends $runs): void
    {
        $this->act(fn (SigningBulkSend $run) => $runs->confirm($this->user(), $this->company(), $run), __('Massutskicket är startat. Signeringarna skickas i bakgrunden; sidan visar hur det går.'));
    }

    public function cancel(ManageSigningBulkSends $runs): void
    {
        $preview = $this->run()->status === SigningBulkSend::PREVIEW;
        $message = $preview ? (string) __('Förhandsgranskningen är borttagen. Inget har skickats.') : (string) __('Massutskicket är avbrutet. Det som redan skickats ligger kvar.');

        if (! $this->act(fn (SigningBulkSend $run) => $runs->cancel($this->user(), $this->company(), $run), $preview ? null : $message)) {
            return;
        }

        if ($preview) {
            Toast::flash($message);
            $this->redirectRoute('signing.bulk-sends');
        }
    }

    public function render(): View
    {
        $run = $this->run();
        $company = $this->company();
        $show = in_array($this->show, ['problem', 'skickade'], true) ? $this->show : 'alla';

        $rows = SigningBulkSendRow::query()
            ->where('bulk_send_id', $run->id)
            ->when($show === 'problem', fn ($query) => $query->whereIn('status', [SigningBulkSendRow::INVALID, SigningBulkSendRow::FAILED, SigningBulkSendRow::SKIPPED]))
            ->when($show === 'skickade', fn ($query) => $query->where('status', SigningBulkSendRow::SENT))
            ->orderBy('row_number')
            ->paginate(self::PER_PAGE);

        $preview = $run->status === SigningBulkSend::PREVIEW
            ? SigningBulkSendRow::query()->where('bulk_send_id', $run->id)->where('status', SigningBulkSendRow::PENDING)->orderBy('row_number')->limit(self::PREVIEW_ROWS)->get()
            : collect();

        $template = $run->template_id === null ? null : SigningTemplate::query()->with('roles')->find($run->template_id);
        $perEnvelope = $template instanceof SigningTemplate ? app(ProcessSigningBulkSend::class)->mailsPerEnvelope($template) : 1;
        $mails = $perEnvelope * ($run->status === SigningBulkSend::PREVIEW ? $run->valid_rows : max(0, $run->valid_rows - $run->sent_count - $run->failed_count));
        $remaining = MailQuota::remaining($company, 'documents');

        return view('signing-module::livewire.bulk-send', [
            'run' => $run,
            'template' => $template,
            'rows' => $rows,
            'preview' => $preview,
            'show' => $show,
            'mails' => $mails,
            'remaining' => $remaining,
            'limit' => MailQuota::LIMITS['documents'],
            'waiting' => $run->status === SigningBulkSend::RUNNING && $mails > 0 && $remaining < $perEnvelope,
            'hours' => (int) ceil(max(0, $mails - $remaining) / MailQuota::LIMITS['documents']),
            'company' => $company,
        ]);
    }

    private function run(): SigningBulkSend
    {
        return SigningBulkSend::query()->with(['template:id,name', 'creator:id,name'])->findOrFail($this->bulkSendId);
    }

    /** @param  callable(SigningBulkSend): mixed  $do */
    private function act(callable $do, ?string $done): bool
    {
        try {
            $do($this->run());
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return false;
        } catch (AuthorizationException) {
            abort(404);
        }

        if ($done !== null) {
            $this->toast($done);
        }

        return true;
    }
}

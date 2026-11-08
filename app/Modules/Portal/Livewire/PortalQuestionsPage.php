<?php

declare(strict_types=1);

namespace App\Modules\Portal\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Portal\Actions\PortalQuestions;
use App\Modules\Portal\Models\PortalPaymentNote;
use App\Modules\Portal\Models\PortalThread;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * /kunder/portal/fragor (ADR-075 §6): the customers' questions and
 * disputes, newest first, filtered on open or closed (in the address), one
 * thread open with its messages, Svara, Avsluta and Spara (kept past the 24
 * months). Message text is shown as plain text; files open through the
 * company's own download route, and a reply can carry files too.
 */
class PortalQuestionsPage extends Component
{
    use ShowsToasts;
    use WithFileUploads;
    use WithPagination;

    public const string NAME = 'portal.questions';

    #[Url(as: 'visa')]
    public string $filter = 'oppna';

    #[Url(as: 'trad')]
    public ?int $threadId = null;

    public string $reply = '';

    /** @var array<int, mixed> files for the reply, checked like the customer's (PortalQuestions::screen) */
    public array $replyFiles = [];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'customer-portal:view'), 403);
    }

    public function open(int $threadId): void
    {
        $this->threadId = PortalThread::query()->findOrFail($threadId)->id;
        $this->reset('reply');
    }

    public function send(PortalQuestions $questions): void
    {
        $files = [];

        foreach ($this->replyFiles as $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages(['files' => __('Filerna kunde inte läsas. Välj dem igen.')]);
            }

            $files[] = $file;
        }

        $questions->reply($this->user(), $this->company(), $this->thread(), $this->reply, $files);
        $this->reset('reply', 'replyFiles');
        $this->toast(__('Svaret är skickat. Kunden får ett mejl med en länk, inte själva texten.'));
    }

    public function close(PortalQuestions $questions, bool $close = true): void
    {
        $questions->close($this->user(), $this->company(), $this->thread(), $close);
        $this->toast($close ? __('Frågan är avslutad.') : __('Frågan är öppen igen.'));
    }

    public function keep(PortalQuestions $questions, bool $keep = true): void
    {
        $questions->keep($this->user(), $this->company(), $this->thread(), $keep);
        $this->toast($keep ? __('Frågan sparas tills du tar bort markeringen.') : __('Frågan gallras 24 månader efter att den avslutades.'));
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $threads = PortalThread::query()->with(['customer:id,name,number'])
            ->where('status', $this->filter === 'avslutade' ? PortalThread::CLOSED : PortalThread::OPEN)
            ->orderByDesc('last_message_at')
            ->paginate(25);

        return view('portal::livewire.questions', [
            'threads' => $threads,
            'thread' => $this->threadId === null ? null : PortalThread::query()->with(['customer:id,name', 'messages.attachments', 'messages.user:id,name', 'messages.contact.identity'])->find($this->threadId),
            'canReply' => $this->user()->hasTeamPermission($this->company(), 'customer-portal:reply'),
            // "Jag har betalat" on invoices still unpaid (ADR-075 §5).
            'paidNotes' => PortalPaymentNote::query()
                ->with(['invoice:id,number,kind,customer_id,status', 'invoice.customer:id,name', 'contact.identity'])
                ->whereHas('invoice', fn ($query) => $query->where('status', 'ISSUED'))
                ->latest('id')->limit(20)->get(),
        ]);
    }

    private function thread(): PortalThread
    {
        return PortalThread::query()->findOrFail((int) $this->threadId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}

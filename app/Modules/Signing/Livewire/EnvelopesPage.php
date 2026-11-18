<?php

declare(strict_types=1);

namespace App\Modules\Signing\Livewire;

use App\Models\SigningEnvelope;
use App\Modules\Signing\SigningModule;
use App\Modules\Signing\Support\EnvelopeVisibility;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Signering → Alla signeringar (ADR-046 amendment, gap 10, package S7): every
 * envelope the member may see, newest first, with the filters out, signed,
 * declined, expired and withdrawn and a search on the title, all kept in the
 * address. Server-side pagination; a fixed number of queries however many
 * rows or signers there are. While the module is off the list is the
 * archive of signed standalone documents.
 */
class EnvelopesPage extends Component
{
    use InCurrentCompany;
    use WithPagination;

    public const int PER_PAGE = 25;

    /** @var array<string, string> filter key => label */
    public const array FILTERS = ['alla' => 'Alla', 'ute' => 'Ute för signering', 'klara' => 'Signerade', 'avbojda' => 'Avböjda', 'utgangna' => 'Har gått ut', 'tillbakadragna' => 'Tillbakadragna'];

    #[Url(as: 'visa', except: 'alla')]
    public string $show = 'alla';

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless($this->allowed(SigningModule::VIEW), 404);
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();
        $show = array_key_exists($this->show, self::FILTERS) ? $this->show : 'alla';
        $search = trim(mb_substr($this->search, 0, 100));

        $envelopes = EnvelopeVisibility::query($user, $company)
            ->withCount(['signers', 'signers as signed_count' => fn (Builder $query) => $query->where('status', 'SIGNED')])
            ->with('creator:id,name')
            ->tap(fn (Builder $query) => $this->filter($query, $show))
            ->when($search !== '', fn (Builder $query) => $query->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('signing-module::livewire.envelopes', [
            'envelopes' => $envelopes,
            'filters' => self::FILTERS,
            'show' => $show,
            'moduleOn' => EnvelopeVisibility::moduleOn($company),
            'canSend' => $this->allowed(SigningModule::SEND) && EnvelopeVisibility::moduleOn($company),
            'now' => CarbonImmutable::now(),
        ]);
    }

    /** @param  Builder<SigningEnvelope>  $query */
    private function filter(Builder $query, string $show): void
    {
        $now = now();

        match ($show) {
            'ute' => $query->where('status', 'OUT')->where(fn (Builder $open) => $open->whereNull('deadline_at')->orWhere('deadline_at', '>', $now)),
            'utgangna' => $query->where('status', 'OUT')->whereNotNull('deadline_at')->where('deadline_at', '<=', $now),
            'klara' => $query->where('status', 'COMPLETED'),
            'avbojda' => $query->where('status', 'DECLINED'),
            'tillbakadragna' => $query->where('status', 'VOIDED'),
            default => null,
        };
    }
}

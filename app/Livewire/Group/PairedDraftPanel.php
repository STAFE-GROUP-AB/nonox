<?php

declare(strict_types=1);

namespace App\Livewire\Group;

use App\Actions\Group\CreatePairedDraft;
use App\Actions\Group\SetPairReference;
use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanyMirror;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\IntercompanyPair;
use App\Models\User;
use App\Models\Voucher;
use App\Policies\IntercompanyDeliveryPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Skapa motsvarande utkast" on a voucher (ADR-081 E27): the pairs the
 * company already has for it, and, on a posted voucher, a form that
 * proposes the mirrored rows for a group company and makes a DRAFT there.
 * The amounts always come from the posted voucher, never from the form; a
 * person changes accounts, texts and codes only. Thin: CreatePairedDraft
 * decides and writes.
 */
class PairedDraftPanel extends Component
{
    use ShowsToasts;

    public const string NAME = 'group.paired-draft-panel';

    #[Locked]
    public string $voucherId;

    public bool $open = false;

    public ?int $counterpartId = null;

    public string $kind = '';

    public string $voucherDate = '';

    public string $description = '';

    public string $series = 'A';

    /** @var list<array{account: string, description: string, cost_center: string, project: string}> */
    public array $rows = [];

    public ?int $editPairId = null;

    public string $reference = '';

    public function mount(string $voucherId): void
    {
        // Read again inside the current company: another company's voucher answers 404.
        $this->voucherId = Voucher::query()->findOrFail($voucherId)->id;
    }

    public function start(CreatePairedDraft $create): void
    {
        $proposal = $create->propose($this->user(), $this->voucher());
        $counterparts = CreatePairedDraft::counterparts($this->user(), $this->company());

        $this->resetErrorBag();
        $this->open = true;
        $this->counterpartId = $counterparts->count() === 1 ? $counterparts->first()?->id : null;
        $this->kind = $proposal['kind'] !== null && in_array($proposal['kind'], IntercompanyMirror::PAIRED_KINDS, true) ? $proposal['kind']->value : '';
        $this->voucherDate = $proposal['voucher_date'];
        $this->description = $proposal['description'];
        $this->series = 'A';
        $this->rows = array_map(fn (array $row): array => [
            'account' => $row['account'],
            'description' => $row['description'],
            'cost_center' => $row['cost_center'],
            'project' => $row['project'],
        ], $proposal['rows']);
    }

    public function cancel(): void
    {
        $this->reset('open', 'counterpartId', 'kind', 'voucherDate', 'description', 'series', 'rows');
        $this->resetErrorBag();
    }

    public function create(CreatePairedDraft $create): void
    {
        $this->resetErrorBag();
        // The rows and amounts are the posted voucher's (CreatePairedDraft takes them from it); only accounts, texts and codes come from the form.
        $create($this->user(), $this->voucher(), (int) $this->counterpartId, [
            'kind' => $this->kind,
            'voucher_date' => trim($this->voucherDate),
            'description' => trim($this->description),
            'series' => trim($this->series),
            'rows' => $this->rows,
        ]);

        $this->cancel();
        $this->toast(__('Ett motsvarande utkast är skapat i koncernföretaget. Det bokförs av den som bokför där.'));
    }

    public function editReference(int $pairId): void
    {
        $pair = IntercompanyPair::query()->where('voucher_id', $this->voucherId)->findOrFail($pairId);
        $this->editPairId = $pair->id;
        $this->reference = (string) $pair->counterparty_voucher_ref;
    }

    public function saveReference(SetPairReference $set): void
    {
        $pair = IntercompanyPair::query()->where('voucher_id', $this->voucherId)->findOrFail((int) $this->editPairId);
        $set($this->user(), $this->company(), $pair->id, $this->reference);

        $this->reset('editPairId', 'reference');
        $this->toast(__('Motpartens verifikation är sparad.'));
    }

    public function render(): View
    {
        $voucher = $this->voucher();
        $company = $this->company();
        $mayPair = $voucher->isPosted() && IntercompanyDeliveryPolicy::mayPair($this->user(), $company);
        $counterparts = $mayPair ? CreatePairedDraft::counterparts($this->user(), $company) : collect();

        return view('livewire.group.paired-draft-panel', [
            'voucher' => $voucher,
            'pairs' => IntercompanyPair::query()->where('voucher_id', $voucher->id)->orderBy('id')->get(),
            'mayPair' => $mayPair,
            'mayEdit' => IntercompanyDeliveryPolicy::mayPair($this->user(), $company),
            'counterparts' => $counterparts,
            'kinds' => IntercompanyMirror::PAIRED_KINDS,
            'mirrorOf' => fn (string $kind): ?IntercompanyKind => IntercompanyKind::tryFrom($kind) === null ? null : IntercompanyMirror::kind(IntercompanyKind::from($kind)),
            'amounts' => $this->open ? app(CreatePairedDraft::class)->propose($this->user(), $voucher)['rows'] : [],
        ]);
    }

    private ?Voucher $loaded = null;

    private function voucher(): Voucher
    {
        return $this->loaded ??= Voucher::query()->findOrFail($this->voucherId);
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->voucher()->company_id);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}

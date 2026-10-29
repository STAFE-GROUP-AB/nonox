<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\DeleteDraftVoucher;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class VoucherActions extends Component
{
    use ShowsToasts;

    #[Locked]
    public string $voucherId;

    public bool $correcting = false;

    public bool $confirmingDelete = false;

    public string $reason = '';

    public string $correctionDate = '';

    public function mount(Voucher $voucher): void
    {
        $this->voucherId = $voucher->id;
        $this->correctionDate = now()->toDateString();
    }

    public function correct(CorrectVoucher $correctVoucher): void
    {
        $voucher = $this->voucher();
        $reference = $voucher->reference();

        $correction = $correctVoucher($this->user(), $voucher, $this->reason, $this->correctionDate);

        Toast::flash(__(':original är rättad med :correction.', [
            'original' => $reference,
            'correction' => $correction->reference(),
        ]));

        $this->redirectRoute('accounting.vouchers.show', ['voucher' => $correction->id]);
    }

    public function deleteDraft(DeleteDraftVoucher $deleteDraft): void
    {
        $deleteDraft($this->user(), $this->voucher());

        Toast::flash(__('Utkastet är borttaget.'));
        $this->redirectRoute('accounting.vouchers.index');
    }

    public function render(): View
    {
        $voucher = $this->voucher();

        return view('livewire.accounting.voucher-actions', [
            'voucher' => $voucher,
            'canCorrect' => $this->user()->can('correct', $voucher),
            'canEdit' => $this->user()->can('update', $voucher),
            'canCopy' => $this->user()->can('create', [Voucher::class, $this->user()->currentCompany()]),
        ]);
    }

    private function voucher(): Voucher
    {
        return Voucher::query()->with('series')->findOrFail($this->voucherId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}

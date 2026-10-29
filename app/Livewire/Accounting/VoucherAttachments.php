<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\RemoveAttachment;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Underlag next to a voucher, with a preview (AGENTS.md §11.2). Files can be
 * added to posted vouchers but only removed from drafts.
 */
class VoucherAttachments extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    #[Locked]
    public string $voucherId;

    public ?int $selectedId = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(Voucher $voucher): void
    {
        $this->voucherId = $voucher->id;
    }

    public function select(int $attachmentId): void
    {
        $this->selectedId = $attachmentId;
    }

    public function updatedUploads(AttachToVoucher $attach): void
    {
        $voucher = $this->voucher();

        foreach ($this->uploads as $upload) {
            try {
                $attachment = $attach($this->user(), $voucher, $upload);
            } catch (ValidationException $exception) {
                $this->addError('uploads', __(':name: :reason', [
                    'name' => $upload->getClientOriginalName(),
                    'reason' => collect($exception->errors())->flatten()->first(),
                ]));

                continue;
            }

            $this->selectedId = $attachment->id;
            $this->toast(__('Underlaget :name är sparat.', ['name' => $attachment->original_name]));
        }

        $this->uploads = [];
    }

    public function remove(int $attachmentId, RemoveAttachment $removeAttachment): void
    {
        $attachment = VoucherAttachment::query()->where('voucher_id', $this->voucherId)->findOrFail($attachmentId);

        $removeAttachment($this->user(), $attachment);

        $this->selectedId = null;
        $this->toast(__('Underlaget :name är borttaget.', ['name' => $attachment->original_name]));
    }

    public function render(): View
    {
        $voucher = $this->voucher();
        $attachments = VoucherAttachment::query()->where('voucher_id', $voucher->id)->orderBy('id')->get();

        return view('livewire.accounting.voucher-attachments', [
            'voucher' => $voucher,
            'attachments' => $attachments,
            'selected' => $attachments->firstWhere('id', $this->selectedId) ?? $attachments->first(),
            'canAttach' => $this->user()->can('attach', $voucher),
            'canRemove' => $this->user()->can('update', $voucher),
        ]);
    }

    private function voucher(): Voucher
    {
        return Voucher::query()->findOrFail($this->voucherId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}

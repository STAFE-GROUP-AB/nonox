<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a draft. Posted vouchers can never be deleted (AGENTS.md §1.2).
 */
class DeleteDraftVoucher
{
    public function __invoke(User $user, Voucher $draft): void
    {
        Gate::forUser($user)->authorize('delete', $draft);

        $paths = VoucherAttachment::query()->where('voucher_id', $draft->id)->pluck('file_path')->all();

        $draft->delete();

        Storage::disk(AttachToVoucher::DISK)->delete($paths);
    }
}

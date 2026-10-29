<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Removes an attachment from a draft. Attachments of posted vouchers are kept
 * for good (AGENTS.md §10).
 */
class RemoveAttachment
{
    public function __invoke(User $user, VoucherAttachment $attachment): void
    {
        $voucher = Voucher::query()->findOrFail($attachment->voucher_id);

        Gate::forUser($user)->authorize('update', $voucher);

        $attachment->delete();
        Storage::disk(AttachToVoucher::DISK)->delete($attachment->file_path);

        Audit::record('voucher.attachment_removed', $voucher, [
            'name' => $attachment->original_name,
            'sha256' => $attachment->sha256,
        ], $voucher->company_id, $user);
    }
}

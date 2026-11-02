<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes an attachment from a draft. Attachments of posted vouchers are kept
 * for good (AGENTS.md §10). The file itself is removed only when no other
 * row refers to it, such as the inbox mail it came from or another voucher.
 */
class RemoveAttachment
{
    public function __invoke(User $user, VoucherAttachment $attachment): void
    {
        $voucher = Voucher::query()->findOrFail($attachment->voucher_id);

        Gate::forUser($user)->authorize('update', $voucher);

        CurrentCompany::run($voucher->company_id, function () use ($user, $voucher, $attachment): void {
            DB::transaction(function () use ($user, $voucher, $attachment): void {
                $attachment->delete();

                $deleted = StoredFiles::deleteUnreferenced([$attachment->file_path]);

                Audit::record('voucher.attachment_removed', $voucher, [
                    'name' => $attachment->original_name,
                    'sha256' => $attachment->sha256,
                    'file_kept' => $deleted === [],
                ], $voucher->company_id, $user);
            });
        });
    }
}

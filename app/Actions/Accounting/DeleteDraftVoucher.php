<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes a draft. Posted vouchers can never be deleted (AGENTS.md §1.2).
 * The draft's underlag is removed from the disk only when nothing else refers
 * to it: a mail from the inbox may be the underlag of a posted voucher too.
 */
class DeleteDraftVoucher
{
    public function __invoke(User $user, Voucher $draft): void
    {
        Gate::forUser($user)->authorize('delete', $draft);

        CurrentCompany::run($draft->company_id, function () use ($draft): void {
            DB::transaction(function () use ($draft): void {
                $paths = VoucherAttachment::query()->where('voucher_id', $draft->id)->pluck('file_path')->all();

                $draft->delete();

                StoredFiles::deleteUnreferenced($paths);
            });
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\SigningRequest;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The PDFs of an account's agreements and their signing links (ADR-046).
 * When the account is deleted (a prospect or a never-booked customer
 * erased under GDPR) the rows go by foreign key; the files are deleted
 * once that has committed, so no orphan stays on the disk. A signed
 * agreement never gets here: its account is not erased.
 */
final class SigningFiles
{
    public static function forAccount(Account $account): void
    {
        $paths = CurrentCompany::run($account->company_id, function () use ($account): array {
            $agreements = Agreement::query()->where('account_id', $account->id)->get(['id', 'document_path']);
            $requests = SigningRequest::query()->whereIn('agreement_id', $agreements->pluck('id'))->get(['document_path', 'signed_document_path']);

            return array_values(array_filter([
                ...$agreements->pluck('document_path')->all(),
                ...$requests->pluck('document_path')->all(),
                ...$requests->pluck('signed_document_path')->all(),
            ], fn (mixed $path): bool => is_string($path) && $path !== ''));
        });

        if ($paths === []) {
            return;
        }

        DB::afterCommit(fn () => Storage::disk(SigningRequest::DISK)->delete($paths));
    }
}

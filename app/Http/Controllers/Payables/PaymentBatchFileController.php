<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\SupplierPaymentBatch;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a payment file exactly as it was made, to read into the bank.
 * A file that has not been approved is never handed out, so it cannot reach
 * the bank without a second person's approval (AGENTS.md §4).
 */
class PaymentBatchFileController
{
    public function __invoke(SupplierPaymentBatch $batch): StreamedResponse|RedirectResponse
    {
        Gate::authorize('view', $batch);

        if (! $batch->canBeDownloaded()) {
            Toast::flash(__(':title är inte godkänd och kan inte laddas ned. Någon med rätt att godkänna betalningar behöver godkänna den först.', ['title' => $batch->title()]), 'error');

            return redirect()->route('payables.batches.index');
        }

        return Storage::disk(AttachToVoucher::DISK)->download($batch->file_path, $batch->filename(), [
            'Content-Type' => 'application/xml',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}

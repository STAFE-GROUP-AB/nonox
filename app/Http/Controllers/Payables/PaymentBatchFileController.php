<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\SupplierPaymentBatch;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a payment file exactly as it was made, to read into the bank.
 */
class PaymentBatchFileController
{
    public function __invoke(SupplierPaymentBatch $batch): StreamedResponse
    {
        Gate::authorize('view', $batch);

        return Storage::disk(AttachToVoucher::DISK)->download($batch->file_path, $batch->filename(), [
            'Content-Type' => 'application/xml',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}

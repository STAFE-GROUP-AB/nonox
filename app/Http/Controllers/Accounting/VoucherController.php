<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Voucher pages. Route model binding goes through the company scope, so
 * another company's voucher answers 404 (AGENTS.md §13.2).
 */
class VoucherController
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('viewAny', [Voucher::class, $user->currentCompany()]);

        return view('accounting.vouchers.index');
    }

    public function create(Request $request): View
    {
        $copyOf = $request->filled('kopia')
            ? Voucher::query()->findOrFail((string) $request->query('kopia'))
            : null;

        return view('accounting.vouchers.edit', ['voucher' => null, 'copyOf' => $copyOf]);
    }

    public function show(Voucher $voucher): View
    {
        Gate::authorize('view', $voucher);

        $voucher->load(['rows.account', 'rows.costCenter', 'rows.project', 'series', 'corrects.series', 'correctedBy.series', 'attachments']);

        return view('accounting.vouchers.show', [
            'voucher' => $voucher,
            'postedBy' => $voucher->posted_by ? User::query()->find($voucher->posted_by) : null,
        ]);
    }

    /**
     * Shows an attachment inline. The type is the stored, validated MIME type,
     * and nosniff stops the browser from guessing another one.
     */
    public function attachment(Voucher $voucher, int $attachment): StreamedResponse
    {
        Gate::authorize('view', $voucher);

        $file = VoucherAttachment::query()->where('voucher_id', $voucher->id)->findOrFail($attachment);

        abort_unless(array_key_exists($file->mime, AttachToVoucher::ALLOWED), 404);

        return Storage::disk(AttachToVoucher::DISK)->response($file->file_path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ], 'inline');
    }

    public function edit(Voucher $voucher): View
    {
        Gate::authorize('update', $voucher);

        return view('accounting.vouchers.edit', ['voucher' => $voucher, 'copyOf' => null]);
    }
}

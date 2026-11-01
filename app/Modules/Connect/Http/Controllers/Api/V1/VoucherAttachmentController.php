<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Modules\Connect\Http\Resources\ApiPresenter;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\ConnectCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Underlag on a voucher (ADR-032): list, add (to a draft or a posted
 * voucher, never removed from a posted one) and download. AttachToVoucher
 * checks the file's real type (PDF or image) and size, as in the UI.
 */
final class VoucherAttachmentController
{
    public function index(Voucher $voucher): JsonResponse
    {
        Gate::forUser($this->credential()->user)->authorize('view', $voucher);

        $attachments = VoucherAttachment::query()->where('voucher_id', $voucher->id)->orderBy('id')->get();

        return new JsonResponse(['data' => $attachments->map(fn (VoucherAttachment $attachment): array => ApiPresenter::attachment($attachment))->values()->all()], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request, Voucher $voucher, AttachToVoucher $attach): JsonResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('Skicka filen i fältet file (multipart/form-data).')]);
        }

        $attachment = $attach($this->credential()->user, $voucher, $file);

        return CursorList::one(ApiPresenter::attachment($attachment), 201);
    }

    public function download(Voucher $voucher, int $attachment): StreamedResponse
    {
        Gate::forUser($this->credential()->user)->authorize('view', $voucher);

        $file = VoucherAttachment::query()->where('voucher_id', $voucher->id)->findOrFail($attachment);

        return Storage::disk(AttachToVoucher::DISK)->download($file->file_path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-SHA256' => $file->sha256,
        ]);
    }

    private function credential(): ConnectCredential
    {
        return ConnectAccess::current() ?? abort(401);
    }
}

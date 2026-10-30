<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores a supporting document (underlag) for a voucher. The file type is
 * taken from the file's content, never from its name, and the stored file name
 * is generated. Attachments may be added after posting (a receipt that arrives
 * later) but never removed then.
 *
 * TODO(legal-verify): confirm that adding underlag to an already posted
 * voucher is acceptable under BFL, as long as the voucher itself is unchanged.
 */
class AttachToVoucher
{
    public const string DISK = 'local';

    /** @var array<string, string> MIME type => stored extension */
    public const array ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public const int MAX_KILOBYTES = 10240;

    /**
     * The MIME type from the file's bytes. The client's name and type, and any
     * wrapper's guess based on the name, are never trusted.
     */
    public static function detectMime(string $contents): string
    {
        return (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
    }

    public function __invoke(User $user, Voucher $voucher, UploadedFile $file): VoucherAttachment
    {
        Gate::forUser($user)->authorize('attach', $voucher);

        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
        ], [
            'file.max' => __('Filen är för stor. Den får vara högst 10 MB.'),
        ])->validate();

        $contents = (string) file_get_contents($file->getRealPath());
        $mime = self::detectMime($contents);

        if (! array_key_exists($mime, self::ALLOWED)) {
            throw ValidationException::withMessages([
                'file' => __('Underlaget måste vara en PDF eller en bild (JPG, PNG eller WebP).'),
            ]);
        }

        $extension = self::ALLOWED[$mime];
        $path = "underlag/{$voucher->company_id}/{$voucher->id}/".Str::uuid()->toString().".{$extension}";

        Storage::disk(self::DISK)->put($path, $contents);

        return CurrentCompany::run($voucher->company_id, function () use ($user, $voucher, $file, $mime, $path, $contents): VoucherAttachment {
            $attachment = VoucherAttachment::create([
                'voucher_id' => $voucher->id,
                'file_path' => $path,
                'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'mime' => $mime,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'uploaded_by' => $user->id,
            ]);

            Audit::record('voucher.attachment_added', $voucher, [
                'attachment_id' => $attachment->id,
                'name' => $attachment->original_name,
                'size' => $attachment->size,
                'sha256' => $attachment->sha256,
                'voucher_posted' => $voucher->isPosted(),
            ], $voucher->company_id, $user);

            return $attachment;
        });
    }

    /**
     * Links a file already stored by the system, such as a registered
     * supplier invoice, to the voucher that books it. The caller has
     * authorised the booking; the file is not copied.
     */
    public function linkStoredFile(User $user, Voucher $voucher, string $path, string $name, string $mime, int $size, string $sha256): VoucherAttachment
    {
        return CurrentCompany::run($voucher->company_id, function () use ($user, $voucher, $path, $name, $mime, $size, $sha256): VoucherAttachment {
            $attachment = VoucherAttachment::create([
                'voucher_id' => $voucher->id,
                'file_path' => $path,
                'original_name' => $name,
                'mime' => $mime,
                'size' => $size,
                'sha256' => $sha256,
                'uploaded_by' => $user->id,
            ]);

            Audit::record('voucher.attachment_added', $voucher, [
                'attachment_id' => $attachment->id,
                'name' => $attachment->original_name,
                'size' => $attachment->size,
                'sha256' => $attachment->sha256,
                'voucher_posted' => $voucher->isPosted(),
            ], $voucher->company_id, $user);

            return $attachment;
        });
    }
}

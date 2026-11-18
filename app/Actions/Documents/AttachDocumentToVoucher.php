<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Accounting\AttachToVoucher;
use App\Domain\Documents\RetentionClass;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\VoucherSeries;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Makes the current version of a räkenskapsinformation document underlag for
 * a voucher of the same fiscal year. It goes through voucher_attachments like
 * all underlag, with the same stored file and checksum, never a copy
 * (ADR-081 gap 9, accounting impact). The voucher is not changed.
 */
class AttachDocumentToVoucher
{
    public function __construct(private readonly AttachToVoucher $attach) {}

    /** @param  string  $reference  the voucher's series and number, such as A12 */
    public function __invoke(User $user, Document $document, string $reference): VoucherAttachment
    {
        Gate::forUser($user)->authorize('view', $document);

        if ($document->retention_class !== RetentionClass::Accounting) {
            throw ValidationException::withMessages(['voucher' => __('Bara räkenskapsinformation kan bli underlag till en verifikation.')]);
        }

        return CurrentCompany::run($document->company_id, function () use ($user, $document, $reference): VoucherAttachment {
            $voucher = $this->voucher($document, $reference);
            Gate::forUser($user)->authorize('attach', $voucher);

            $version = DocumentVersion::query()->findOrFail($document->current_version_id);

            if (VoucherAttachment::query()->where('voucher_id', $voucher->id)->where('file_path', $version->path)->exists()) {
                throw ValidationException::withMessages(['voucher' => __('Den här versionen är redan underlag till verifikationen.')]);
            }

            $attachment = $this->attach->linkStoredFile($user, $voucher, $version->path, $version->original_name, $version->mime, $version->size, $version->sha256);

            Audit::record('document.attached_to_voucher', $document, ['version_id' => $version->id, 'voucher_id' => $voucher->id, 'attachment_id' => $attachment->id], $document->company_id, $user);

            return $attachment;
        });
    }

    private function voucher(Document $document, string $reference): Voucher
    {
        $missing = ValidationException::withMessages(['voucher' => __('Ingen verifikation :reference i räkenskapsåret. Skriv serie och nummer, till exempel A12.', ['reference' => mb_substr(trim($reference), 0, 20)])]);

        if (preg_match('/^\s*([A-Za-z]{1,3})\s*(\d{1,9})\s*$/', $reference, $match) !== 1) {
            throw $missing;
        }

        $series = VoucherSeries::query()->where('code', mb_strtoupper($match[1]))->first();
        $voucher = $series === null ? null : Voucher::query()
            ->where('fiscal_year_id', $document->fiscal_year_id)
            ->where('series_id', $series->id)
            ->where('number', (int) $match[2])
            ->first();

        return $voucher ?? throw $missing;
    }
}

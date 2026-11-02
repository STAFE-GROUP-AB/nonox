<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\PaymentResponseImport;
use App\Models\SupplierPaymentBatch;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\FileName;
use App\Support\Iso20022\BankResponseFile;
use App\Support\Tenancy\CurrentCompany;
use App\Support\WorkQuota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reads the bank's answer to payment files — a status report (pain.002), a
 * debit notification (camt.054) or an account statement (camt.053) — keeps
 * the file as received, and lets ApplyPaymentResponses match and apply each
 * answer in it. The same file is read once (its SHA-256).
 */
class ImportPaymentResponseFile
{
    public const int MAX_KILOBYTES = 10240;

    public function __construct(private readonly ApplyPaymentResponses $apply) {}

    /**
     * @return array{import: PaymentResponseImport, created: int, skipped: int, ignored: int, applied: int, open: int}
     */
    public function __invoke(User $user, Company $company, UploadedFile $file): array
    {
        Gate::forUser($user)->authorize('create', [SupplierPaymentBatch::class, $company]);

        WorkQuota::take($company, 'bank_file', 'file');

        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages(['file' => __('Filen är för stor. Den får vara högst 10 MB.')]);
        }

        $contents = (string) file_get_contents($file->getRealPath());

        try {
            $report = BankResponseFile::read($contents);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['file' => __('Filen går inte att läsa som ett svar på en betalfil. Hämta statusrapporten (pain.002), debiteringsaviseringen (camt.054) eller kontoutdraget (camt.053) i ISO 20022-format från banken.')]);
        }

        $sha256 = hash('sha256', $contents);

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $file, $contents, $report, $sha256): array {
            $earlier = PaymentResponseImport::query()->where('file_sha256', $sha256)->first();

            if ($earlier instanceof PaymentResponseImport) {
                throw ValidationException::withMessages(['file' => __('Filen är redan inläst :date. Inget har ändrats.', ['date' => $earlier->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i')])]);
            }

            $path = "betalsvar/{$company->id}/".Str::uuid()->toString().'.xml';
            Storage::disk(AttachToVoucher::DISK)->put($path, $contents);

            $import = PaymentResponseImport::create([
                'format' => $report->format(),
                'file_name' => FileName::clean($file->getClientOriginalName()),
                'file_path' => $path,
                'file_sha256' => $sha256,
                'message_id' => $report->messageId() === null ? null : mb_substr($report->messageId(), 0, 35),
                'created_by' => $user->id,
            ]);

            $totals = $this->apply->record($company, $report->events(), $import, $user);

            Audit::record('payment_response_file.imported', $import, [
                'file' => $import->file_name,
                'format' => $import->format,
                'sha256' => $sha256,
                ...$totals,
            ], $company, $user);

            return ['import' => $import, ...$totals];
        }));
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Accounting\AttachToVoucher;
use App\Domain\Invoicing\IncomingPaymentMatcher;
use App\Models\BankFileImport;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Camt054\BankCredit;
use App\Support\Camt054\CreditNotification;
use App\Support\FileName;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reads a file of incoming payments from the bank (camt.054) and suggests the
 * invoice for each payment. Payments already read from an earlier file are
 * skipped, so a payment is never booked twice. Nothing is booked here.
 */
class ImportBankFile
{
    public const int MAX_KILOBYTES = 10240;

    /**
     * @return array{import: BankFileImport, skipped: int}
     */
    public function __invoke(User $user, Company $company, UploadedFile $file): array
    {
        Gate::forUser($user)->authorize('create', [BankFileImport::class, $company]);

        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages(['file' => __('Filen är för stor. Den får vara högst 10 MB.')]);
        }

        $contents = (string) file_get_contents($file->getRealPath());

        try {
            $notification = CreditNotification::read($contents);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['file' => __('Filen går inte att läsa som en avisering av inbetalningar (camt.054). Hämta filen i ISO 20022-format från banken.')]);
        }

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $file, $contents, $notification): array {
            $known = BankTransaction::query()
                ->whereIn('reference', array_map(fn (BankCredit $credit): string => $credit->reference, $notification->credits))
                ->pluck('reference')
                ->all();
            $new = array_values(array_filter($notification->credits, fn (BankCredit $credit): bool => ! in_array($credit->reference, $known, true)));

            $path = "bankfiler/{$company->id}/".Str::uuid()->toString().'.xml';
            Storage::disk(AttachToVoucher::DISK)->put($path, $contents);

            $import = BankFileImport::create([
                'file_name' => FileName::clean($file->getClientOriginalName()),
                'file_path' => $path,
                'file_sha256' => hash('sha256', $contents),
                'message_id' => $notification->messageId === null ? null : mb_substr($notification->messageId, 0, 35),
                'credit_count' => count($new),
                'credit_total_ore' => array_sum(array_map(fn (BankCredit $credit): int => $credit->amountOre, $new)),
                'created_by' => $user->id,
            ]);

            $matcher = new IncomingPaymentMatcher;

            foreach ($new as $credit) {
                BankTransaction::create([
                    'bank_file_import_id' => $import->id,
                    'reference' => $credit->reference,
                    'booking_date' => $credit->bookingDate,
                    'amount_ore' => $credit->amountOre,
                    'ocr' => $credit->ocr,
                    'message' => $credit->message,
                    'payer_name' => $credit->payerName,
                    'invoice_id' => $matcher->match($credit)?->id,
                ]);
            }

            Audit::record('bank_file.imported', $import, [
                'file' => $import->file_name,
                'sha256' => $import->file_sha256,
                'credits' => count($new),
                'skipped' => count($known),
            ], $company, $user);

            return ['import' => $import, 'skipped' => count($known)];
        }));
    }
}

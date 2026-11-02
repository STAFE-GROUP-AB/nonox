<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp;

use App\Actions\Accounting\AnalyseSieFile;
use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Models\ConnectSftpUpload;
use App\Support\Audit\Audit;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Takes a file that arrived over SFTP into the ordinary two-step SIE import
 * (ADR-032, "SFTP"; AGENTS.md §9.2). The job carries the company id and runs
 * inside it (RunsForCompany).
 *
 * 1. The account must still be usable (SftpAccess), checked again.
 * 2. The file must be a plain file directly in the company's own /in folder
 *    (no link, no other directory), not empty, not larger than the SIE
 *    import allows, without NUL bytes and starting with #FLAGGA.
 * 3. It is checked by AnalyseSieFile in the responsible person's name. That
 *    stores an encrypted copy and a report, and nothing is booked: a person
 *    must read the report and confirm, exactly as for a file uploaded in the
 *    browser. There is deliberately no automatic mode.
 * 4. The file is always removed from the drop afterwards, read in or not.
 * 5. The responsible person and the owner get a notice, the upload is
 *    audited, and SieFileUploaded is dispatched for webhooks (sie.uploaded).
 */
class ProcessSftpUpload implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $companyId, public string $uploadId) {}

    public function handle(AnalyseSieFile $analyse): void
    {
        $claimed = ConnectSftpUpload::query()
            ->whereKey($this->uploadId)
            ->where('status', ConnectSftpUpload::RECEIVED)
            ->update(['status' => ConnectSftpUpload::PROCESSING, 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $upload = ConnectSftpUpload::query()->findOrFail($this->uploadId);
        $account = ConnectSftpAccount::query()->with('responsible')->findOrFail($upload->account_id);
        $company = Company::query()->findOrFail($this->companyId);
        $path = SftpConfig::inbox($account->username).'/'.basename($upload->file_name);

        // An archived company is read-only (ADR-024): nothing can be recorded, so the file is only removed.
        if ($company->isArchived()) {
            if (is_file($path) && ! is_link($path)) {
                @unlink($path);
            }

            return;
        }

        try {
            $this->process($analyse, $upload, $account, $company, $path);
        } catch (Throwable $exception) {
            report($exception);
            $this->finish($upload, $company, ConnectSftpUpload::REJECTED, __('Något oväntat gick fel när filen lästes. Skicka den igen, och hör av dig om det händer igen.'));
        } finally {
            if (is_file($path) && ! is_link($path)) {
                @unlink($path);
            }
        }

        $upload->refresh();
        $this->notify($upload, $account, $company);
        event(new SieFileUploaded($company->id, $upload->id));
    }

    private function process(AnalyseSieFile $analyse, ConnectSftpUpload $upload, ConnectSftpAccount $account, Company $company, string $path): void
    {
        if (SftpAccess::refusal($account, $company, checkIp: false) !== null || ! $account->responsible instanceof User) {
            $this->finish($upload, $company, ConnectSftpUpload::REJECTED, __('SFTP är avstängt för företaget, eller så har den ansvariga personen inte längre behörighet att läsa in SIE-filer.'));

            return;
        }

        $problem = $this->checkFile($account, $path);

        if ($problem !== null) {
            $this->finish($upload, $company, ConnectSftpUpload::REJECTED, $problem);

            return;
        }

        $sha256 = (string) hash_file('sha256', $path);

        $import = $analyse->fromPath($account->responsible, $company, $path, $upload->file_name, [
            'via' => 'sftp',
            'sftp_upload_id' => $upload->id,
        ]);

        $upload->forceFill(['sha256' => $sha256, 'sie_import_id' => $import->id])->save();
        $this->finish($upload, $company, ConnectSftpUpload::ANALYSED, null);
    }

    /** A Swedish reason when the file must not be read, otherwise null. */
    private function checkFile(ConnectSftpAccount $account, string $path): ?string
    {
        $inbox = realpath(SftpConfig::inbox($account->username));

        if ($inbox === false || is_link($path) || ! is_file($path) || dirname((string) realpath($path)) !== $inbox) {
            return __('Filen fanns inte kvar i SFTP-mappen in.');
        }

        $size = (int) filesize($path);

        if ($size === 0) {
            return __('Filen är tom.');
        }

        if ($size > SftpConfig::maxFileBytes()) {
            return __('Filen är för stor. Den får vara högst :mb MB.', ['mb' => intdiv(SftpConfig::maxFileBytes(), 1024 * 1024)]);
        }

        $head = (string) file_get_contents($path, false, null, 0, 65_536);

        if (str_contains($head, "\0")) {
            return __('Filen är inte en textfil och kan inte vara en SIE-fil.');
        }

        // SIE files start with #FLAGGA (SIE 4C, section 5); a UTF-8 byte order mark in front is tolerated.
        $firstLine = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);

        if (! str_starts_with($firstLine, '#FLAGGA')) {
            return __('Filen börjar inte som en SIE-fil (#FLAGGA saknas först i filen).');
        }

        return null;
    }

    private function finish(ConnectSftpUpload $upload, Company $company, string $status, ?string $reason): void
    {
        DB::transaction(function () use ($upload, $company, $status, $reason): void {
            $upload->forceFill(['status' => $status, 'reason' => $reason === null ? null : mb_substr($reason, 0, 255), 'processed_at' => now()])->save();

            Audit::record($status === ConnectSftpUpload::ANALYSED ? 'connect.sftp.upload_analysed' : 'connect.sftp.upload_rejected', $upload, [
                'file' => $upload->file_name,
                'size' => $upload->size,
                'sha256' => $upload->sha256,
                'sie_import_id' => $upload->sie_import_id,
                'reason' => $reason,
            ], $company);
        });
    }

    /** The responsible person and the owner, while they are still members. */
    private function notify(ConnectSftpUpload $upload, ConnectSftpAccount $account, Company $company): void
    {
        $notice = SftpUploadNotice::about($upload, $company);

        $recipients = User::query()->whereIn('id', array_unique([$account->responsible_user_id, $company->user_id]))->get();

        foreach ($recipients as $recipient) {
            if ($recipient->belongsToTeam($company)) {
                $recipient->notify($notice);
            }
        }
    }
}

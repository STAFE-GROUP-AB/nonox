<?php

declare(strict_types=1);

namespace App\Support\Archive;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\ExportSie;
use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\Reports\ReportTable;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\IssuedInvoicePdf;
use App\Domain\Vat\EskdFile;
use App\Domain\Vat\VatReturn;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\VatReport;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Modules\Boardroom\Support\BoardroomArchive;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\ImportedPayslip;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\PayslipPdf;
use App\Support\Build\SystemDocumentation;
use App\Support\Build\VersionRecorder;
use App\Support\Csv;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Builds the archive package for one fiscal year (AGENTS.md §10): every
 * file is written to a working folder, its SHA-256 is taken from what was
 * written, and the folder is zipped with a manifest. The package is meant to
 * be read without this system: PDF, CSV, SIE and XML only, and a text file
 * that explains it in Swedish.
 */
final class ArchivePackageBuilder
{
    /**
     * Rows per report PDF. Dompdf needs about 0.4 MB per table row: 500 rows
     * take some 200 MB and a second, so a long general ledger becomes several
     * PDFs instead of exhausting memory (measured 2026-10-31).
     */
    public static int $rowsPerPdf = 500;

    private const string SYSTEMDOKUMENTATION = 'docs/systemdokumentation';

    private string $root = '';

    /** @var array<string, array{size: int, sha256: string}> */
    private array $files = [];

    public function __construct(private readonly ExportSie $exportSie, private readonly ReportTable $reports) {}

    /**
     * @return array{path: string, name: string, size: int, sha256: string, file_count: int, contents: array<string, int>}
     */
    public function build(User $user, FiscalYear $fiscalYear): array
    {
        return CurrentCompany::run($fiscalYear->company_id, function () use ($user, $fiscalYear): array {
            $company = Company::query()->findOrFail($fiscalYear->company_id);
            $name = self::name($company, $fiscalYear);
            $this->root = storage_path('app/archive-work/'.bin2hex(random_bytes(8)));
            $this->files = [];
            File::ensureDirectoryExists($this->root);

            try {
                $contents = [
                    'sie' => $this->sie($user, $company, $fiscalYear),
                    'rapporter' => $this->ledgerReports($company, $fiscalYear),
                    'underlag' => $this->voucherAttachments($fiscalYear),
                    'kundfakturor' => $this->customerInvoices($fiscalYear),
                    'leverantorsfakturor' => $this->supplierInvoices($fiscalYear),
                    'moms' => $this->vatReturns($company, $fiscalYear),
                    'lon' => $user->hasTeamPermission($company, 'payroll:archive-view') ? $this->payroll($fiscalYear) : 0,
                    'aktiebok' => $this->boardroom($user, $company, $fiscalYear),
                    'behandlingshistorik' => $this->auditLog($company, $fiscalYear),
                    'systemdokumentation' => $this->systemDocumentation($fiscalYear),
                ];

                $this->readMe($user, $company, $fiscalYear, $contents);
                $this->manifest($user, $company, $fiscalYear);
                $zip = $this->zip($name);

                $path = "archives/{$company->id}/{$name}.zip";
                $stream = fopen($zip, 'rb') ?: throw new RuntimeException('The archive package could not be opened.');
                Storage::disk(AttachToVoucher::DISK)->put($path, $stream);
                fclose($stream);

                return [
                    'path' => $path,
                    'name' => "{$name}.zip",
                    'size' => (int) filesize($zip),
                    'sha256' => (string) hash_file('sha256', $zip),
                    'file_count' => count($this->files),
                    'contents' => $contents,
                ];
            } finally {
                File::deleteDirectory($this->root);
                @unlink("{$this->root}.zip");
            }
        });
    }

    /** The folder and file name: organisation number and the fiscal year. */
    public static function name(Company $company, FiscalYear $fiscalYear): string
    {
        $org = (string) preg_replace('/\D/', '', (string) $company->org_number);

        return "Arkiv_{$org}_{$fiscalYear->start_date->format('Ymd')}-{$fiscalYear->end_date->format('Ymd')}";
    }

    private function sie(User $user, Company $company, FiscalYear $fiscalYear): int
    {
        $path = 'sie/'.ExportSie::filename($company, $fiscalYear);
        $stream = $this->open($path);
        ($this->exportSie)($user, $fiscalYear, $stream);
        fclose($stream);
        $this->record($path);

        return 1;
    }

    private function ledgerReports(Company $company, FiscalYear $fiscalYear): int
    {
        $query = LedgerQuery::forYear($fiscalYear);
        $count = 0;

        foreach (ReportTable::TITLES as $report => $title) {
            $rows = $this->reports->rows($report, $query);
            $this->csv("rapporter/{$title}.csv", $rows);
            $count++;

            $heading = array_shift($rows) ?? [];
            $parts = array_chunk($rows, max(1, self::$rowsPerPdf)) ?: [[]];

            foreach ($parts as $index => $part) {
                $suffix = count($parts) > 1 ? '-del-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) : '';
                $this->put("rapporter/{$title}{$suffix}.pdf", $this->reportPdf($company, $fiscalYear, $title, $heading, $part, $index + 1, count($parts)));
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<string|int>  $heading
     * @param  list<list<string|int>>  $rows
     */
    private function reportPdf(Company $company, FiscalYear $fiscalYear, string $title, array $heading, array $rows, int $part, int $parts): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('archive.report-pdf', [
            'company' => $company,
            'fiscalYear' => $fiscalYear,
            'title' => __($title),
            'heading' => $heading,
            'rows' => $rows,
            'part' => $part,
            'parts' => $parts,
        ])->render());
        $pdf->setPaper('A4', count($heading) > 6 ? 'landscape' : 'portrait');
        $pdf->render();
        $output = (string) $pdf->output();
        unset($pdf);
        gc_collect_cycles();

        return $output;
    }

    private function voucherAttachments(FiscalYear $fiscalYear): int
    {
        $vouchers = Voucher::query()
            ->with('series:id,code')
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', VoucherStatus::Posted->value)
            ->get(['id', 'series_id', 'number'])
            ->keyBy('id');
        $count = 0;

        VoucherAttachment::query()
            ->whereIn('voucher_id', $vouchers->keys())
            ->orderBy('id')
            ->each(function (VoucherAttachment $attachment) use ($vouchers, &$count): void {
                $voucher = $vouchers->get($attachment->voucher_id);

                if ($voucher instanceof Voucher && $this->copy($attachment->file_path, 'verifikationer/'.self::safe($voucher->reference()).'/'.self::safe($attachment->original_name))) {
                    $count++;
                }
            });

        return $count;
    }

    private function customerInvoices(FiscalYear $fiscalYear): int
    {
        $count = 0;

        Invoice::query()
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->whereDate('invoice_date', '>=', $fiscalYear->start_date->toDateString())
            ->whereDate('invoice_date', '<=', $fiscalYear->end_date->toDateString())
            ->orderBy('number')
            ->each(function (Invoice $invoice) use (&$count): void {
                $this->put('kundfakturor/'.self::safe(InvoicePdf::filename($invoice)), IssuedInvoicePdf::bytes($invoice));
                $count++;
            });

        return $count;
    }

    private function supplierInvoices(FiscalYear $fiscalYear): int
    {
        $count = 0;

        SupplierInvoice::query()
            ->whereNotNull('file_path')
            ->whereDate('invoice_date', '>=', $fiscalYear->start_date->toDateString())
            ->whereDate('invoice_date', '<=', $fiscalYear->end_date->toDateString())
            ->orderBy('number')
            ->each(function (SupplierInvoice $invoice) use (&$count): void {
                $name = 'Leverantorsfaktura-'.($invoice->number ?? 'utkast').'-'.($invoice->file_name ?? basename((string) $invoice->file_path));

                if ($this->copy((string) $invoice->file_path, 'leverantorsfakturor/'.self::safe($name))) {
                    $count++;
                }
            });

        return $count;
    }

    private function vatReturns(Company $company, FiscalYear $fiscalYear): int
    {
        $reports = VatReport::query()
            // A period belongs to the year its settlement voucher is in: the year of its last day (#59).
            ->whereDate('period_end', '>=', $fiscalYear->start_date->toDateString())
            ->whereDate('period_end', '<=', $fiscalYear->end_date->toDateString())
            ->orderBy('period_start')
            ->orderBy('version')
            ->get();

        if ($reports->isEmpty()) {
            return 0;
        }

        // Every version of a return is kept; a period booked again after a late posting has more than one (#63).
        $rows = [[__('Period från'), __('Period till'), __('Version'), __('Ruta'), __('Belopp, kronor')]];

        foreach ($reports as $report) {
            foreach ($report->boxes as $box => $amount) {
                $rows[] = [$report->period_start->toDateString(), $report->period_end->toDateString(), (string) $report->version, (string) $box, (string) $amount];
            }
        }

        $periods = $reports->unique(fn (VatReport $report): string => $report->period_start->toDateString());

        foreach ($periods as $report) {
            $return = VatReturn::calculate($fiscalYear, $report->period_start, $report->period_end);
            $this->put('moms/'.self::safe(EskdFile::filename($company, $return)), (string) mb_convert_encoding(EskdFile::render($company, $return), 'ISO-8859-1', 'UTF-8'));
        }

        $this->csv('moms/Momsdeklarationer.csv', $rows);

        return $periods->count() + 1;
    }

    /**
     * Payslips, imported payslips and employer declarations paid out in the
     * year, when the company has payroll.
     */
    private function payroll(FiscalYear $fiscalYear): int
    {
        $from = $fiscalYear->start_date->toDateString();
        $to = $fiscalYear->end_date->toDateString();
        $count = 0;

        $runs = PayrollRun::query()
            ->whereIn('status', [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED])
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->get()
            ->keyBy('id');

        Payslip::query()->whereIn('payroll_run_id', $runs->keys())->orderBy('id')->each(function (Payslip $payslip) use ($runs, &$count): void {
            $run = $runs->get($payslip->payroll_run_id);

            if ($run instanceof PayrollRun) {
                $this->put('lon/lonebesked/'.self::safe(PayslipPdf::filename($payslip, $run)), PayslipPdf::render($payslip));
                $count++;
            }
        });

        $months = [$fiscalYear->start_date->format('Y-m'), $fiscalYear->end_date->format('Y-m')];

        ImportedPayslip::query()->whereBetween('period', $months)->orderBy('id')->each(function (ImportedPayslip $payslip) use (&$count): void {
            if ($this->copy($payslip->file_path, 'lon/lonebesked-fran-tidigare-system/'.self::safe($payslip->original_name))) {
                $count++;
            }
        });

        AgiSubmission::query()->whereBetween('period', $months)->orderBy('period')->orderBy('sequence')->each(function (AgiSubmission $submission) use (&$count): void {
            if ($this->copy($submission->xml_path, 'lon/arbetsgivardeklarationer/'.self::safe($submission->filename()))) {
                $count++;
            }
        });

        return $count;
    }

    /**
     * The share register at the year end, its whole history and the approved
     * minutes of the year (ADR-070), when the company keeps them in NONOX.
     */
    private function boardroom(User $user, Company $company, FiscalYear $fiscalYear): int
    {
        $files = BoardroomArchive::files($user, $company, $fiscalYear);
        $count = 0;

        foreach ($files['generated'] as $path => $contents) {
            $this->put($path, $contents);
            $count++;
        }

        foreach ($files['stored'] as $path => $stored) {
            if ($this->copy($stored, $path)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Everything recorded for the company from the first day of the year
     * until the package is made: the year's own work and its closing.
     */
    private function auditLog(Company $company, FiscalYear $fiscalYear): int
    {
        $users = User::query()
            ->whereIn('id', AuditLog::query()->forCompany($company)->whereNotNull('user_id')->where('created_at', '>=', $fiscalYear->start_date->startOfDay())->select('user_id'))
            ->get(['id', 'name', 'email'])
            ->keyBy('id');
        $csv = $this->open('behandlingshistorik/Behandlingshistorik.csv');
        $json = $this->open('behandlingshistorik/Behandlingshistorik.json');
        fwrite($csv, "\xEF\xBB\xBF");
        fputcsv($csv, [__('Tidpunkt'), __('Användare'), __('E-post'), __('Åtgärd'), __('Avser'), __('Id'), __('Uppgifter'), __('IP-adress')], ';', '"', '');
        fwrite($json, '[');
        $first = true;

        AuditLog::query()
            // The company's own entries and the program versions taken into use (BFNAR 2013:2 p. 9.16; #43).
            ->where(fn ($query) => $query->where('company_id', $company->id)->orWhere(fn ($query) => $query->whereNull('company_id')->where('action', VersionRecorder::ACTION)))
            ->where('created_at', '>=', $fiscalYear->start_date->startOfDay())
            ->orderBy('id')
            ->each(function (AuditLog $entry) use ($users, $csv, $json, &$first): void {
                $user = $entry->user_id === null ? null : $users->get($entry->user_id);
                $payload = (string) json_encode($entry->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $created = $entry->created_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i:s');

                fputcsv($csv, Csv::row([$created, (string) $user?->name, (string) $user?->email, $entry->action, class_basename((string) $entry->subject_type), (string) $entry->subject_id, $payload, (string) $entry->getAttribute('ip_address')]), ';', '"', '');
                fwrite($json, ($first ? '' : ',')."\n".json_encode([
                    'id' => $entry->id,
                    'created_at' => $created,
                    'user' => $user?->name,
                    'email' => $user?->email,
                    'action' => $entry->action,
                    'subject_type' => $entry->subject_type,
                    'subject_id' => $entry->subject_id,
                    'payload' => $entry->payload,
                    'ip_address' => $entry->getAttribute('ip_address'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $first = false;
            });

        fwrite($json, "\n]\n");
        fclose($csv);
        fclose($json);
        $this->record('behandlingshistorik/Behandlingshistorik.csv');
        $this->record('behandlingshistorik/Behandlingshistorik.json');

        return 2;
    }

    /**
     * The systemdokumentation as it reads when the package is made, and
     * every version that applied during the fiscal year with the time it was
     * taken into use (BFL 5 kap. 11 §; BFNAR 2013:2, comment on p. 9.1; #44).
     */
    private function systemDocumentation(FiscalYear $fiscalYear): int
    {
        $count = 0;

        foreach (File::allFiles(base_path(self::SYSTEMDOKUMENTATION)) as $file) {
            $this->put('systemdokumentation/'.str_replace('\\', '/', $file->getRelativePathname()), (string) file_get_contents($file->getPathname()));
            $count++;
        }

        $versions = SystemDocumentation::forFiscalYear($fiscalYear);
        $lines = [
            __('Systemdokumentationen som gällde under räkenskapsåret :start – :end.', ['start' => $fiscalYear->start_date->toDateString(), 'end' => $fiscalYear->end_date->toDateString()]),
            __('Filen README.md bredvid är den version som gäller när paketet skapas.'),
            '',
        ];

        foreach ($versions as $version) {
            $name = 'versioner/'.($version->version ?? 'okand').'-'.substr($version->sha256, 0, 12).'.md';
            $this->put("systemdokumentation/{$name}", $version->content);
            $count++;
            $lines[] = __(':file – version :version, i bruk från :date (programversion :program).', [
                'file' => $name,
                'version' => $version->version ?? '?',
                'date' => $version->recorded_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'),
                'program' => $version->program_version ?? '?',
            ]);
        }

        if ($versions->isEmpty()) {
            $lines[] = __('Den här installationen har inte sparat någon version från räkenskapsåret. Ändringshistoriken i README.md visar vad som ändrats och när.');
        }

        $this->put('systemdokumentation/versioner.txt', implode("\r\n", $lines)."\r\n");

        return $count + 1;
    }

    /**
     * @param  array<string, int>  $contents
     */
    private function readMe(User $user, Company $company, FiscalYear $fiscalYear, array $contents): void
    {
        $this->put('LASMIG.txt', str_replace("\n", "\r\n", view('archive.readme', [
            'company' => $company,
            'fiscalYear' => $fiscalYear,
            'user' => $user,
            'contents' => $contents,
            'createdAt' => CarbonImmutable::now('Europe/Stockholm'),
        ])->render()));
    }

    /**
     * A JSON manifest and a SHA256SUMS file that `sha256sum -c` and
     * `shasum -a 256 -c` read, both listing every other file.
     */
    private function manifest(User $user, Company $company, FiscalYear $fiscalYear): void
    {
        $files = $this->files;
        ksort($files);
        $sums = '';

        foreach ($files as $path => $file) {
            $sums .= "{$file['sha256']}  {$path}\n";
        }

        $manifest = [
            'format' => 'NONOX arkivpaket 1',
            'company' => ['name' => $company->name, 'legal_name' => $company->getAttribute('legal_name'), 'org_number' => $company->org_number],
            'fiscal_year' => ['start' => $fiscalYear->start_date->toDateString(), 'end' => $fiscalYear->end_date->toDateString(), 'status' => $fiscalYear->status],
            'created_at' => CarbonImmutable::now('Europe/Stockholm')->toIso8601String(),
            'created_by' => ['name' => $user->name, 'email' => $user->email],
            'hash_algorithm' => 'SHA-256',
            'files' => array_map(fn (string $path, array $file): array => ['path' => $path, ...$file], array_keys($files), array_values($files)),
        ];

        $this->put('SHA256SUMS.txt', $sums, false);
        $this->put('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
    }

    private function zip(string $name): string
    {
        $target = "{$this->root}.zip";
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The archive package could not be created.');
        }

        foreach (File::allFiles($this->root) as $file) {
            $zip->addFile($file->getPathname(), "{$name}/".str_replace('\\', '/', $file->getRelativePathname()));
        }

        if (! $zip->close()) {
            throw new RuntimeException('The archive package could not be written.');
        }

        return $target;
    }

    /**
     * @param  list<list<string|int>>  $rows
     */
    private function csv(string $path, array $rows): void
    {
        $out = $this->open($path);
        fwrite($out, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($out, Csv::row($row), ';', '"', '');
        }

        fclose($out);
        $this->record($path);
    }

    /** Copies a stored file into the package; false when it is missing. */
    private function copy(string $storedPath, string $path): bool
    {
        $disk = Storage::disk(AttachToVoucher::DISK);

        if (! $disk->exists($storedPath)) {
            return false;
        }

        $path = $this->unique($path);
        $in = $disk->readStream($storedPath) ?? throw new RuntimeException("{$storedPath} could not be read.");
        $out = $this->open($path);
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $this->record($path);

        return true;
    }

    private function put(string $path, string $contents, bool $listed = true): void
    {
        $path = $listed ? $this->unique($path) : $path;
        File::ensureDirectoryExists(dirname("{$this->root}/{$path}"));
        file_put_contents("{$this->root}/{$path}", $contents);

        if ($listed) {
            $this->record($path);
        }
    }

    /** @return resource */
    private function open(string $path)
    {
        File::ensureDirectoryExists(dirname("{$this->root}/{$path}"));

        return fopen("{$this->root}/{$path}", 'wb') ?: throw new RuntimeException("{$path} could not be written.");
    }

    private function record(string $path): void
    {
        $full = "{$this->root}/{$path}";
        $this->files[$path] = ['size' => (int) filesize($full), 'sha256' => (string) hash_file('sha256', $full)];
    }

    /** Two underlag with the same name on one voucher both stay. */
    private function unique(string $path): string
    {
        if (! isset($this->files[$path])) {
            return $path;
        }

        $info = pathinfo($path);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : "{$info['dirname']}/";
        $extension = isset($info['extension']) ? ".{$info['extension']}" : '';

        for ($n = 2; isset($this->files[$candidate = "{$dir}{$info['filename']}-{$n}{$extension}"]); $n++) {
            // Count up until the name is free.
        }

        return $candidate;
    }

    /** A file or folder name that every operating system accepts. */
    private static function safe(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\/\\\\:*?"<>|]+/u', '_', $name), ' .');

        return $name === '' ? 'fil' : mb_substr($name, 0, 150);
    }
}

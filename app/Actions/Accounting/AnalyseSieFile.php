<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\SieImport\SieImportAnalyser;
use App\Models\Company;
use App\Models\SieImport;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\FileName;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Step one of the SIE import: saves the uploaded file privately, checks it
 * without changing any bookkeeping, and stores the report for the user to
 * read before confirming (AGENTS.md §9.2).
 */
class AnalyseSieFile
{
    /** Kilobytes; matches the server's upload limit. */
    public const int MAX_KILOBYTES = 51_200;

    public function __construct(private readonly SieImportAnalyser $analyser) {}

    public function __invoke(User $user, Company $company, mixed $file): SieImport
    {
        Gate::forUser($user)->authorize('create', [SieImport::class, $company]);

        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
        ], [
            'file.required' => __('Välj en SIE-fil att läsa in.'),
            'file.file' => __('Välj en SIE-fil att läsa in.'),
            'file.max' => __('Filen är för stor. Den får vara högst 50 MB.'),
        ])->validate();

        /** @var UploadedFile $file */
        return $this->analyse($user, $company, (string) $file->getRealPath(), $file->getClientOriginalName(), (int) $file->getSize());
    }

    /**
     * The same step for a file that is already on the server, such as one
     * that arrived over SFTP (ADR-032, "SFTP"). The caller has checked the
     * size; the file at $absolutePath is left where it is.
     *
     * @param  array<string, mixed>  $auditPayload  added to the audit entry, e.g. where the file came from
     */
    public function fromPath(User $user, Company $company, string $absolutePath, string $originalName, array $auditPayload = []): SieImport
    {
        Gate::forUser($user)->authorize('create', [SieImport::class, $company]);

        return $this->analyse($user, $company, $absolutePath, $originalName, (int) filesize($absolutePath), $auditPayload);
    }

    /**
     * @param  array<string, mixed>  $auditPayload
     */
    private function analyse(User $user, Company $company, string $absolute, string $originalName, int $size, array $auditPayload = []): SieImport
    {
        $directory = "sie-import/{$company->id}";
        // The stored copy is encrypted; the file itself is read for the checksum and the analysis.
        $path = Storage::disk(ImportSie::DISK)->putFileAs($directory, new File($absolute), Str::uuid()->toString().'.se');
        $sha256 = (string) hash_file('sha256', $absolute);

        $plan = $this->analyser->analyse($company, $absolute, $sha256);

        return CurrentCompany::run($company, function () use ($company, $user, $originalName, $size, $path, $sha256, $plan, $auditPayload): SieImport {
            $import = SieImport::create([
                'user_id' => $user->id,
                'original_name' => FileName::clean($originalName),
                'file_path' => (string) $path,
                'size' => $size,
                'sha256' => $sha256,
                'report' => $plan->toArray(),
            ]);

            Audit::record('sie.analysed', $import, [
                'file' => $import->original_name,
                'sha256' => $sha256,
                'fiscal_year' => $plan->fiscalYearLabel(),
                'vouchers' => $plan->vouchers,
                'blockers' => count($plan->blockers),
                ...$auditPayload,
            ], $company, $user);

            return $import;
        });
    }
}

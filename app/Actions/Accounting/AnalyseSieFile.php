<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\SieImport\SieImportAnalyser;
use App\Models\Company;
use App\Models\SieImport;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
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
        $directory = "sie-import/{$company->id}";
        $path = Storage::disk(ImportSie::DISK)->putFileAs($directory, $file, Str::uuid()->toString().'.se');
        $absolute = Storage::disk(ImportSie::DISK)->path((string) $path);
        $sha256 = (string) hash_file('sha256', $absolute);

        $plan = $this->analyser->analyse($company, $absolute, $sha256);

        return CurrentCompany::run($company, function () use ($company, $user, $file, $path, $sha256, $plan): SieImport {
            $import = SieImport::create([
                'user_id' => $user->id,
                'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'file_path' => (string) $path,
                'size' => (int) $file->getSize(),
                'sha256' => $sha256,
                'report' => $plan->toArray(),
            ]);

            Audit::record('sie.analysed', $import, [
                'file' => $import->original_name,
                'sha256' => $sha256,
                'fiscal_year' => $plan->fiscalYearLabel(),
                'vouchers' => $plan->vouchers,
                'blockers' => count($plan->blockers),
            ], $company, $user);

            return $import;
        });
    }
}

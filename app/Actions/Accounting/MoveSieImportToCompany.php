<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\SieImport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * A SIE file uploaded in the wrong company: the user switches to the
 * company with the file's organisation number and the same file is
 * analysed there, so nothing has to be uploaded again. Nothing is booked
 * in either company until the user confirms the new report.
 */
class MoveSieImportToCompany
{
    public function __construct(private readonly AnalyseSieFile $analyseSieFile) {}

    /**
     * The user's companies whose organisation number is the file's, other
     * than the one the file was uploaded in.
     *
     * @return list<Company>
     */
    public static function candidates(User $user, SieImport $import): array
    {
        $theirs = OrganisationNumber::tryFrom((string) ($import->report['organisation_number'] ?? ''));

        if ($theirs === null) {
            return [];
        }

        return array_values($user->companies()
            ->filter(fn (Company $company): bool => $company->id !== $import->company_id
                && OrganisationNumber::tryFrom((string) $company->org_number)?->digits === $theirs->digits)
            ->all());
    }

    public function __invoke(User $user, SieImport $import, Company $target): SieImport
    {
        Gate::forUser($user)->authorize('view', $import);

        if (! in_array($target->id, array_map(fn (Company $company): int => $company->id, self::candidates($user, $import)), true)) {
            throw new AuthorizationException;
        }

        $user->switchCompany($target);

        return ($this->analyseSieFile)($user, $target, new UploadedFile(
            Storage::disk(ImportSie::DISK)->path($import->file_path),
            $import->original_name,
            'text/plain',
            null,
            true,
        ));
    }
}

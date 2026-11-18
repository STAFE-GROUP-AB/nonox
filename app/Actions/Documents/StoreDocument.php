<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Domain\Documents\DocumentDetails;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\RetentionClass;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentTag;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Uploads a new company document with its first version (ADR-081 gap 9).
 * The person states whether it holds personal data, its category and its
 * retention class; retain_until is computed from the rule.
 */
class StoreDocument
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?UploadedFile $file): Document
    {
        Gate::forUser($user)->authorize('create', [Document::class, $company]);

        $details = DocumentDetails::validate($user, $company, $input);

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('Välj en fil.')]);
        }

        $stored = null;

        try {
            return CurrentCompany::run($company->id, function () use ($user, $company, $details, $file, &$stored): Document {
                return DB::transaction(function () use ($user, $company, $details, $file, &$stored): Document {
                    $document = Document::create([
                        ...$details['attributes'],
                        'company_id' => $company->id,
                        'legal_hold' => false,
                        'created_by' => $user->id,
                    ]);

                    $stored = DocumentFile::store($company->id, $document->id, $file, $document->retention_class !== RetentionClass::Personnel);

                    $version = DocumentVersion::create([
                        ...$stored,
                        'company_id' => $company->id,
                        'document_id' => $document->id,
                        'version' => 1,
                        'uploaded_by' => $user->id,
                    ]);

                    $document->forceFill(['current_version_id' => $version->id])->save();

                    foreach ($details['tags'] as $tag) {
                        DocumentTag::create(['company_id' => $company->id, 'document_id' => $document->id, 'tag' => $tag]);
                    }

                    Audit::record('document.created', $document, [
                        'retention_class' => $document->retention_class->value,
                        'fiscal_year_id' => $document->fiscal_year_id,
                        'retain_until' => $document->retain_until?->toDateString(),
                        'contains_personal_data' => $document->contains_personal_data,
                        'tag_count' => count($details['tags']),
                        'version_id' => $version->id,
                        'size' => $version->size,
                        'sha256' => $version->sha256,
                    ], $company, $user);

                    return $document;
                });
            });
        } catch (Throwable $exception) {
            // The row was rolled back: the file it would have pointed at goes too.
            if ($stored !== null && StoredFiles::referenced([$stored['path']]) === []) {
                Storage::disk(StoredFiles::DISK)->delete($stored['path']);
            }

            throw $exception;
        }
    }
}

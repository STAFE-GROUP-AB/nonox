<?php

declare(strict_types=1);

namespace App\Migration\Import\Actions;

use App\Actions\Accounting\ImportSie;
use App\Migration\Import\AttachmentZip;
use App\Migration\Import\PresetRepository;
use App\Migration\Import\RegisterFileReader;
use App\Migration\Import\RegisterKind;
use App\Models\Company;
use App\Models\RegisterImport;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\FileName;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Step 1 of a register import (ADR-076): stores the file privately (encrypted
 * at rest), reads its header and detects encoding and delimiter, and
 * pre-fills the mapping from the chosen source. Nothing is changed in the
 * registers. The same file is not read in twice.
 */
class UploadRegisterFile
{
    public const int MAX_KILOBYTES = 20_480;

    public function __invoke(User $user, Company $company, RegisterKind $kind, string $source, mixed $file): RegisterImport
    {
        Gate::forUser($user)->authorize('create', [RegisterImport::class, $company]);

        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:'.($kind === RegisterKind::Attachments ? AttachmentZip::MAX_ZIP_KILOBYTES : self::MAX_KILOBYTES)],
        ], [
            'file.required' => __('Välj en fil att läsa in.'),
            'file.file' => __('Välj en fil att läsa in.'),
            'file.max' => $kind === RegisterKind::Attachments ? __('ZIP-filen är större än 100 MB. Dela upp den.') : __('Filen är större än 20 MB. Dela upp den i flera filer.'),
        ])->validate();

        /** @var UploadedFile $file */
        $absolute = (string) $file->getRealPath();
        $sha256 = (string) hash_file('sha256', $absolute);

        return CurrentCompany::run($company, function () use ($user, $company, $kind, $source, $file, $absolute, $sha256): RegisterImport {
            $earlier = RegisterImport::query()->where('kind', $kind->value)->where('sha256', $sha256)->where('status', RegisterImport::COMPLETED)->latest('id')->first();

            if ($earlier !== null) {
                throw ValidationException::withMessages(['file' => __('Den här filen har redan lästs in (import nr :id). Rätta filen och ladda upp den igen om något behöver läsas in på nytt.', ['id' => $earlier->id])]);
            }

            $table = null;

            if ($kind === RegisterKind::Attachments) {
                if (! str_starts_with((string) file_get_contents($absolute, false, null, 0, 4), "PK\x03\x04")) {
                    throw ValidationException::withMessages(['file' => __('Välj en ZIP-fil.')]);
                }
            } else {
                try {
                    $table = RegisterFileReader::open($absolute);
                } catch (RuntimeException $error) {
                    throw ValidationException::withMessages(['file' => $error->getMessage()]);
                }
            }

            $suggestion = $table === null ? ['mapping' => [], 'value_maps' => []] : PresetRepository::suggest($kind, $source, $table->headers);
            $detected = $table === null ? [] : $table->detected();
            $extension = $kind === RegisterKind::Attachments ? 'zip' : (($detected['format'] ?? '') === 'xlsx' ? 'xlsx' : 'csv');
            $path = Storage::disk(ImportSie::DISK)->putFileAs("register-import/{$company->id}", new File($absolute), Str::uuid()->toString().'.'.$extension);

            return DB::transaction(function () use ($user, $company, $kind, $source, $file, $sha256, $path, $detected, $suggestion): RegisterImport {
                $import = RegisterImport::create([
                    'user_id' => $user->id,
                    'kind' => $kind->value,
                    'original_name' => FileName::clean($file->getClientOriginalName()),
                    'file_path' => $path,
                    'size' => (int) $file->getSize(),
                    'sha256' => $sha256,
                    'options' => [
                        'source' => $source,
                        'detected' => $detected,
                        'reader' => ['sheet' => $detected['sheet'] ?? null],
                        'mapping' => $suggestion['mapping'],
                        'value_maps' => $suggestion['value_maps'],
                        'fill_empty' => false,
                        'excluded' => [],
                    ],
                ]);

                Audit::record('register.uploaded', $import, ['kind' => $kind->value, 'sha256' => $sha256, 'size' => $import->size], $company, $user);

                return $import;
            });
        });
    }
}

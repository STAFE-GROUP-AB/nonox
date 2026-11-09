<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Migration\Import\Actions\AnalyseRegisterImport;
use App\Migration\Import\Actions\ConfirmRegisterImport;
use App\Migration\Import\Actions\UploadRegisterFile;
use App\Migration\Import\RegisterKind;
use App\Models\Company;
use App\Models\RegisterImport;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;

/**
 * Helpers for the register import tests: synthetic files only.
 */
final class RegisterImports
{
    public static function fixture(string $name): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'fx').'-'.$name;
        copy(base_path("tests/Fixtures/import/{$name}"), $copy);
        register_shutdown_function(fn () => @unlink($copy));

        return new UploadedFile($copy, $name, null, null, true);
    }

    public static function contents(string $contents, string $name = 'fil.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fx').'-'.$name;
        file_put_contents($path, $contents);
        register_shutdown_function(fn () => @unlink($path));

        return new UploadedFile($path, $name, null, null, true);
    }

    public static function upload(User $user, Company $company, RegisterKind $kind, UploadedFile $file, string $source = 'nonox'): RegisterImport
    {
        return app(UploadRegisterFile::class)($user, $company, $kind, $source, $file);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function analyse(User $user, RegisterImport $import, array $input = []): RegisterImport
    {
        return app(AnalyseRegisterImport::class)($user, $import, $input);
    }

    /**
     * Upload, analyse and (when there are no blockers) confirm; the queue is synchronous in tests.
     *
     * @param  array<string, mixed>  $input
     */
    public static function run(User $user, Company $company, RegisterKind $kind, UploadedFile $file, string $source = 'nonox', array $input = []): RegisterImport
    {
        $import = self::upload($user, $company, $kind, $file, $source);
        $import = self::analyse($user, $import, $input);

        if ($import->canBeConfirmed()) {
            app(ConfirmRegisterImport::class)($user, $import);
        }

        return CurrentCompany::run($company, fn () => RegisterImport::query()->findOrFail($import->id));
    }
}

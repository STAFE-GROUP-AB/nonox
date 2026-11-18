<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\StoreDocument;
use App\Models\ArchivePackage;
use App\Models\Company;
use App\Models\Document;
use App\Models\FiscalYear;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Shared set-up for the document archive tests (ADR-081 E19). The private
 * disk is pointed at a temporary folder with the same encrypted driver as in
 * production, so the tests see what is really written.
 */
final class DocumentSupport
{
    public static function useTemporaryDisk(): string
    {
        $root = storage_path('framework/testing/documents-'.bin2hex(random_bytes(6)));
        config(['filesystems.disks.local' => ['driver' => 'encrypted', 'root' => $root, 'throw' => false]]);
        Storage::forgetDisk('local');

        return $root;
    }

    public static function removeTemporaryDisk(string $root): void
    {
        File::deleteDirectory($root);
        Storage::forgetDisk('local');
    }

    public static function pdf(string $name = 'avtal.pdf', string $text = 'Testdokument'): UploadedFile
    {
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>'.e($text).'</p>');
        $pdf->render();

        return UploadedFile::fake()->createWithContent($name, (string) $pdf->output());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function store(User $user, Company $company, array $input = [], ?UploadedFile $file = null): Document
    {
        return app(StoreDocument::class)($user, $company, [
            'title' => 'Hyresavtal lokal',
            'category' => 'AGREEMENT',
            'retention_class' => 'OTHER',
            'contains_personal_data' => '0',
            ...$input,
        ], $file ?? self::pdf());
    }

    /**
     * A finished archive package, unpacked: path inside the package folder => contents.
     *
     * @return array<string, string>
     */
    public static function unpack(ArchivePackage $package): array
    {
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'e19-arkiv');
        file_put_contents($zipPath, (string) Storage::disk('local')->get((string) $package->file_path));
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[substr($name, (int) strpos($name, '/') + 1)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($zipPath);

        return $files;
    }

    public static function fiscalYear(Company $company): FiscalYear
    {
        return FiscalYear::query()->where('company_id', $company->id)->orderBy('start_date')->firstOrFail();
    }
}

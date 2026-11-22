<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Migration\Import\Actions\AnalyseRegisterImport;
use App\Migration\Import\Actions\UploadRegisterFile;
use App\Migration\Import\PresetRepository;
use App\Migration\Import\RegisterKind;
use App\Support\Demo\DemoMode;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Http\UploadedFile;

/**
 * Läs in register, price lists (ADR-082 P10, ADR-076) in the joinery, for
 * the manual's moving page: a price file checked but not read in, so the
 * report shows prices that would be added, a quantity step, a price that
 * already exists and a row naming an article the register does not have.
 * Made-up lists, articles and prices.
 */
final class PricingImportExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-hantverkare'];
    }

    public function afterAll(DemoContext $c): void
    {
        if (DemoMode::onDemoDatabase()) {
            return;
        }

        $rows = [
            ['ENTREPRENAD', 'SEK', 'ARB', '', '545,00', ''],
            ['ENTREPRENAD', 'SEK', 'ARB', '100', '515,00', ''],
            ['ENTREPRENAD', 'SEK', 'MAT', '', '2 250,00', ''],
            ['ENTREPRENAD', 'SEK', 'RES', '', '400,00', ''],
            ['ENTREPRENAD', 'SEK', 'VERKTYG', '', '120,00', ''],
            ['BRF', 'SEK', 'RES', '', '400,00', ''],
        ];
        $csv = PresetRepository::template(RegisterKind::PriceLists).implode("\r\n", array_map(fn (array $row): string => implode(';', $row), $rows))."\r\n";
        $path = tempnam(sys_get_temp_dir(), 'priser');

        if ($path === false) {
            return;
        }

        file_put_contents($path, $csv);

        try {
            $this->atomically($c, 'Läs in prislistor', fn () => CurrentCompany::run($c->company, function () use ($c, $path): void {
                $import = app(UploadRegisterFile::class)($c->user, $c->company, RegisterKind::PriceLists, 'nonox', new UploadedFile($path, 'priser-fran-tidigare-program.csv', 'text/csv', null, true));
                app(AnalyseRegisterImport::class)($c->user, $import, []);
            }));
        } finally {
            @unlink($path);
        }
    }
}

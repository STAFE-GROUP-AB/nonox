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
use Database\Seeders\Demo\DemoIndustries;
use Illuminate\Http\UploadedFile;

/**
 * Läs in register (ADR-076) in the consultancy, for the manual's moving
 * page: a customer file in NONOX's own template, uploaded and checked but
 * not yet read in, so the report shows what would be added, what already
 * exists and a row with an error. Every customer in it is made up.
 */
final class RegisterImportExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        if (DemoMode::onDemoDatabase()) {
            return;
        }

        $header = PresetRepository::template(RegisterKind::Customers);
        $rows = [
            ['2001', 'Kunden Exempel AB', 'Nej', DemoIndustries::orgNumber('18'), '', '', 'faktura@kunden.test', '08-123 450 00', 'Exempelvägen 1', '', '111 22', 'Stockholm', 'SE', '30', 'SEK', 'Ja'],
            ['2002', 'Arkitektkontoret Exempel AB', 'Nej', DemoIndustries::orgNumber('31'), '', DemoIndustries::vatNumber('31'), 'ekonomi@arkitekt-exempel.test', '', 'Ritargatan 4', '', '113 51', 'Stockholm', 'SE', '30', 'SEK', 'Ja'],
            ['2003', 'Föreningen Exempelgården', 'Nej', DemoIndustries::orgNumber('32', '802'), '', '', 'kassor@exempelgarden.test', '', 'Gårdsvägen 2', '', '186 30', 'Vallentuna', 'SE', '20', 'SEK', 'Ja'],
            ['2004', 'Konsultringen Exempel HB', 'Nej', DemoIndustries::orgNumber('33', '969'), '', '', 'inte-en-adress', '', 'Ringvägen 9', '', '118 60', 'Stockholm', 'SE', '30', 'SEK', 'Ja'],
            ['2005', 'Webbyrån Exempel AB', 'Nej', DemoIndustries::orgNumber('34'), '', DemoIndustries::vatNumber('34'), 'faktura@webbyran-exempel.test', '', 'Kodgatan 3', '', '411 05', 'Göteborg', 'SE', '30', 'EUR', 'Nej'],
        ];
        $csv = $header.implode("\r\n", array_map(fn (array $row): string => implode(';', $row), $rows))."\r\n";
        $path = tempnam(sys_get_temp_dir(), 'kunder');

        if ($path === false) {
            return;
        }

        file_put_contents($path, $csv);

        try {
            $this->atomically($c, 'Läs in register', fn () => CurrentCompany::run($c->company, function () use ($c, $path): void {
                $import = app(UploadRegisterFile::class)($c->user, $c->company, RegisterKind::Customers, 'nonox', new UploadedFile($path, 'kunder-fran-tidigare-program.csv', 'text/csv', null, true));
                app(AnalyseRegisterImport::class)($c->user, $import, []);
            }));
        } finally {
            @unlink($path);
        }
    }
}

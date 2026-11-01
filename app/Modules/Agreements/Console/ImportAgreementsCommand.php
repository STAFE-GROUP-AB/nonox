<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Console;

use App\Modules\Agreements\Actions\ImportAgreementRules;
use App\Modules\Agreements\Support\InvalidAgreementFile;
use Illuminate\Console\Command;

/**
 * Reads agreement data files into agreement_rule_versions as unverified
 * versions (ADR-033 §2): one file, or every shipped file. A verified
 * version is never touched. Versions count in a run only after
 * agreements:verify or a company's own check.
 */
class ImportAgreementsCommand extends Command
{
    protected $signature = 'agreements:import {file? : Sökväg till en avtalsfil; utan den läses alla filer som följer med NONOX}';

    protected $description = 'Läser in avtalsdata som overifierade versioner';

    public function handle(ImportAgreementRules $import): int
    {
        $files = $this->argument('file') === null ? ImportAgreementRules::shippedFiles() : [(string) $this->argument('file')];
        $failed = 0;
        $counts = [];

        foreach ($files as $path) {
            $this->line('Läser '.basename(dirname($path)).'/'.basename($path).'...');

            try {
                $status = $import($path)['status'];
                $counts[$status] = ($counts[$status] ?? 0) + 1;

                if ($status === ImportAgreementRules::VERIFIED_KEPT) {
                    $this->warn('  Filen skiljer sig från en kontrollerad version, som lämnas orörd. Lägg rättelsen som en ny version med nytt datum.');
                }
            } catch (InvalidAgreementFile $exception) {
                $failed++;

                foreach ($exception->problems as $problem) {
                    $this->error("  {$problem}");
                }
            }
        }

        $this->comment(sprintf(
            '%d nya, %d uppdaterade, %d oförändrade, %d kontrollerade lämnade orörda, %d med fel.',
            $counts[ImportAgreementRules::IMPORTED] ?? 0,
            $counts[ImportAgreementRules::UPDATED] ?? 0,
            $counts[ImportAgreementRules::UNCHANGED] ?? 0,
            $counts[ImportAgreementRules::VERIFIED_KEPT] ?? 0,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

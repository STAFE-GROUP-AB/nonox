<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Signing\Pdf\PdfEngineStatus;
use Illuminate\Console\Command;

/**
 * Checks the PDF engine of this installation (ADR-046, amendment 2026-11-18,
 * S5): which driver is chosen, the Python and library versions against the
 * lock file, the fonts, and a self-test that inspects, renders, stamps,
 * appends to, seals and validates a generated PDF with a throwaway key. It
 * reads no company data and writes nothing outside its own temporary work
 * directory, and refreshes the cached status that /hq shows.
 *
 * Exit codes: 0 the engine works, 1 it is chosen but fails, 2 it is not
 * installed (normal for an installation without it).
 */
class SigningEngineDoctorCommand extends Command
{
    protected $signature = 'signing:engine-doctor {--json : print the result as JSON} {--quick : versions and fonts only, without the self-test}';

    protected $description = 'Kontrollerar PDF-motorn för signering: versioner, typsnitt och ett självtest på en genererad PDF.';

    public function handle(PdfEngineStatus $status): int
    {
        $health = $status->refresh(selfTest: ! $this->option('quick'));

        if ($this->option('json')) {
            $this->line((string) json_encode($health->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Drivrutin: {$health->driver}");

            if ($health->versions !== []) {
                $this->table(['Komponent', 'Version'], array_map(
                    fn (string $name, string|int|null $version): array => [$name, (string) ($version ?? '–')],
                    array_keys($health->versions),
                    array_values($health->versions),
                ));
            }

            $this->line('Sandlåda: '.($health->sandboxed ? 'på (bubblewrap)' : 'av'));

            foreach ($health->fonts as $font => $present) {
                $this->line(sprintf('Typsnitt %s: %s', $font, $present ? 'finns' : 'saknas'));
            }

            foreach ($health->selfTest ?? [] as $step => $passed) {
                $this->line(sprintf('Självtest %s: %s', $step, match ($passed) {
                    true => 'OK',
                    false => 'MISSLYCKADES',
                    null => 'hoppades över',
                }));
            }

            foreach ($health->problems as $problem) {
                $this->warn("Varning: {$problem}");
            }
        }

        if (! $health->installed) {
            $this->comment('PDF-motorn är inte installerad. Signering fungerar ändå; fält och försegling kräver motorn.');

            return 2;
        }

        if (! $health->working) {
            $this->error('PDF-motorn fungerar inte.');

            return self::FAILURE;
        }

        $this->info('OK: PDF-motorn fungerar.');

        return self::SUCCESS;
    }
}

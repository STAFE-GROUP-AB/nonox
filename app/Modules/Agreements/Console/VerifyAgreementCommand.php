<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Console;

use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;

/**
 * Marks an agreement version as verified for the whole installation by a
 * named, qualified person who has compared every value with its source
 * (ADR-033 §2), like payroll:verify-rule. Afterwards it can never change,
 * except its valid_to. A version with a value marked unverified cannot be
 * verified.
 */
class VerifyAgreementCommand extends Command
{
    protected $signature = 'agreements:verify {key : Avtalets nyckel, t.ex. visita-hrf-grona-riks} {valid_from : ÅÅÅÅ-MM-DD} {--variant=MEMBER : MEMBER eller HANGAVTAL} {--by= : Namnet på den som har jämfört värdena med källorna}';

    protected $description = 'Markerar en avtalsversion som kontrollerad mot källorna';

    public function handle(): int
    {
        $by = trim((string) $this->option('by'));

        if ($by === '') {
            $this->error('Ange vem som har kontrollerat versionen med --by="Namn".');

            return self::INVALID;
        }

        $version = AgreementRuleVersion::query()
            ->where('agreement_key', (string) $this->argument('key'))
            ->where('variant', mb_strtoupper((string) $this->option('variant')))
            ->whereDate('valid_from', (string) $this->argument('valid_from'))
            ->first();

        if (! $version instanceof AgreementRuleVersion) {
            $this->error('Versionen finns inte. Läs in den med agreements:import först.');

            return self::FAILURE;
        }

        if ($version->isVerified()) {
            $this->info("Versionen är redan kontrollerad av {$version->verified_by}.");

            return self::SUCCESS;
        }

        if ($version->agreementRules()->hasUnverifiedValues()) {
            $this->error('Versionen innehåller värden som bara finns i en sekundär källa och kan inte kontrolleras.');

            return self::FAILURE;
        }

        $this->line("{$version->agreementRules()->name()} ({$version->variant}) från {$version->valid_from->toDateString()}");

        foreach ($version->sources as $source) {
            $this->line("Källa {$source['id']}: {$source['title']} {$source['url']}");
        }

        $version->forceFill(['verified_by' => $by, 'verified_at' => now()])->save();
        Audit::record('agreements.version_verified', $version, ['agreement_key' => $version->agreement_key, 'variant' => $version->variant, 'valid_from' => $version->valid_from->toDateString(), 'checksum' => $version->checksum, 'by' => $by]);

        $this->comment("Kontrollerad av {$by}.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Signing\Seal\LongTermValidation;
use App\Jobs\TimestampSigningEnvelope;
use Illuminate\Console\Command;

/**
 * The nightly long-term validation of sealed files (ADR-046 amendment,
 * gap 5, package S11): queues the validation data (B-LT) of B-T seals that
 * do not have it yet, and a renewing document timestamp (B-LTA) for files
 * whose newest timestamp stops being reliable within
 * nonox.signing.ltv.renew_months, both only within retention or under a legal
 * hold. Does nothing unless sealing is switched on, the PDF engine is
 * installed and timestamp servers are configured.
 */
class SigningTimestampCommand extends Command
{
    protected $signature = 'signing:timestamp';

    protected $description = 'Lägger till valideringsdata och nya tidsstämplar i förseglade dokument innan tidsstämplarna slutar gälla.';

    public function handle(): int
    {
        if (! LongTermValidation::applies()) {
            $this->info('Långtidsvalidering körs inte: försegling är avstängd, PDF-motorn saknas eller ingen tidsstämpelserver är inställd.');

            return self::SUCCESS;
        }

        $due = LongTermValidation::due();

        foreach ($due as $envelope) {
            $this->line("Köar långtidsvalidering av {$envelope->id}...");
            TimestampSigningEnvelope::dispatch($envelope->company_id, $envelope->id);
        }

        $this->info("Signeringar som köades för långtidsvalidering: {$due->count()}");

        return self::SUCCESS;
    }
}

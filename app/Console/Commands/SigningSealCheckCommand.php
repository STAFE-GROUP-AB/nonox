<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Signing\ManageSealCertificates;
use App\Domain\Signing\Seal\SealCertificates;
use App\Domain\Signing\Seal\SealStatus;
use App\Jobs\SealSigningEnvelope;
use App\Models\SigningEnvelope;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The seal's hourly check (ADR-046 amendment, gap 5, package S10):
 *
 * - a to-do for each company seal certificate that expires within 60 days
 *   (once per certificate, for the uploader or the owner);
 * - every envelope still PENDING more than 15 minutes after it completed is
 *   queued again, so a seal job lost by a worker never leaves its completion
 *   mails waiting (the job seals only a PENDING envelope, under a lock);
 * - a warning in the log when the instance seal expires within 60 days.
 */
class SigningSealCheckCommand extends Command
{
    protected $signature = 'signing:seal-check';

    protected $description = 'Påminner om förseglingscertifikat som snart går ut och köar om förseglingar som har fastnat.';

    public function handle(ManageSealCertificates $certificates): int
    {
        $now = CarbonImmutable::now();
        $raised = $certificates->raiseExpiryTasks($now);
        $this->info("Att göra om förseglingscertifikat: {$raised}");

        // Across companies on purpose (ADR-046 S10): only ids; each job runs inside its own company.
        $stuck = CompanyScope::bypass(fn () => SigningEnvelope::query()
            ->where('seal_status', SealStatus::Pending->value)
            ->where('completed_at', '<', $now->subMinutes(15))
            ->orderBy('completed_at')
            ->limit(500)
            ->get(['id', 'company_id']));

        foreach ($stuck as $envelope) {
            $this->line("Köar förseglingen av {$envelope->id} igen...");
            SealSigningEnvelope::dispatch($envelope->company_id, $envelope->id);
        }

        $this->info("Förseglingar som köades igen: {$stuck->count()}");

        $instance = SealCertificates::instance();

        if ($instance !== null && $instance->info->notAfter->lessThanOrEqualTo($now->addDays(SealCertificates::EXPIRY_WARNING_DAYS))) {
            $this->warn('Installationens förseglingscertifikat går ut '.$instance->info->notAfter->setTimezone((string) config('app.timezone'))->format('Y-m-d').'. Skapa ett nytt med signing:seal-certificate --force.');
            Log::warning('The instance signing seal expires soon (ADR-046 S10).', ['not_after' => $instance->info->notAfter->toIso8601String()]);
        }

        return self::SUCCESS;
    }
}

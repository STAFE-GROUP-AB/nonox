<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Signing\SigningReminders;
use App\Domain\Signing\EnvelopeStatus;
use App\Models\SigningEnvelope;
use App\Support\Tenancy\ArchivedCompany;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Reminders and expiry of envelopes that are out (ADR-046 amendment, gap 9,
 * package S9): in every company that has an envelope out, lets the ones past
 * their last day expire and reminds the open signers of the ones whose sender
 * switched reminders on (SigningReminders::run). Runs hourly; reminders go
 * only between 08:00 and 18:00 Stockholm time.
 *
 * Idempotent: a company is run under a cache lock, and a reminder is sent
 * under the signer's row lock with the stored last_reminded_at as the marker,
 * so a rerun in the same window sends nothing twice. Archived companies are
 * left alone: nothing is sent in their name.
 */
class RemindSignersCommand extends Command
{
    protected $signature = 'signing:remind {--company= : Only this company id}';

    protected $description = 'Påminner undertecknare och låter signeringar som passerat sista dag gå ut.';

    public function handle(SigningReminders $reminders): int
    {
        $now = CarbonImmutable::now();
        // Only which companies have an envelope out is read across companies; each is run inside its own.
        $companies = CompanyScope::bypass(fn (): array => SigningEnvelope::query()
            ->where('status', EnvelopeStatus::Out->value)
            ->whereNull('expired_at')
            ->when($this->option('company') !== null, fn ($query) => $query->where('company_id', (int) $this->option('company')))
            ->distinct()
            ->orderBy('company_id')
            ->pluck('company_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
        $expired = 0;
        $reminded = 0;

        foreach ($companies as $companyId) {
            if (ArchivedCompany::isArchived($companyId)) {
                continue;
            }

            $lock = Cache::lock("signing-remind:{$companyId}", 900);

            if (! $lock->get()) {
                $this->warn("Company `{$companyId}` is already being run, skipping.");

                continue;
            }

            try {
                $this->info("Checking signing deadlines and reminders in company `{$companyId}`...");
                $result = CurrentCompany::run($companyId, fn (): array => $reminders->run($now));
                $expired += $result['expired'];
                $reminded += $result['reminded'];
            } finally {
                $lock->release();
            }
        }

        $this->comment('Checked '.count($companies)." companies, {$expired} envelopes expired, {$reminded} reminders sent.");

        return self::SUCCESS;
    }
}

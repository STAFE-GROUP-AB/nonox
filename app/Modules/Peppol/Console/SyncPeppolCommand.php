<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Console;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\AcceptPeppolDocument;
use App\Modules\Peppol\Actions\RecordTransmissionStatus;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Peppol\PeppolModule;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * The safety net under the webhook (ADR-050): for every company with
 * Peppol on and an account with the access point, fetches documents that
 * have arrived and asks how documents sent in the last 14 days fared.
 * Scheduled every 15 minutes; each company runs in its own context.
 */
class SyncPeppolCommand extends Command
{
    protected $signature = 'peppol:sync {company? : one company id}';

    protected $description = 'Fetch received Peppol invoices and delivery status from the access point';

    public function handle(ModuleManager $modules, AcceptPeppolDocument $accept, RecordTransmissionStatus $record): int
    {
        if (! AccessPoints::isConfigured()) {
            $this->comment('No Peppol access point is configured on this installation.');

            return self::SUCCESS;
        }

        $ids = $this->argument('company') !== null ? [(int) $this->argument('company')] : $modules->companiesWith(PeppolModule::KEY);
        $driver = AccessPoints::make();

        foreach ($ids as $id) {
            $company = Company::query()->find($id);

            if (! $company instanceof Company || ! $modules->isEnabled(PeppolModule::KEY, $company)) {
                continue;
            }

            $this->info("Syncing company `{$company->id}`...");

            CurrentCompany::run($company, function () use ($company, $driver, $accept, $record): void {
                $participant = PeppolParticipant::query()->first();

                if (! $participant instanceof PeppolParticipant || ! $participant->canSend()) {
                    return;
                }

                try {
                    if ($participant->canReceive()) {
                        foreach ($driver->incoming($participant) as $document) {
                            $accept($company, $document);
                        }
                    }

                    PeppolTransmission::query()
                        ->where('status', TransmissionStatus::Sent->value)
                        ->where('driver', $driver->key())
                        ->whereNotNull('provider_reference')
                        ->where('sent_at', '>=', now()->subDays(14))
                        ->orderBy('sent_at')
                        ->limit(100)
                        ->get()
                        ->each(function (PeppolTransmission $transmission) use ($driver, $participant, $record): void {
                            $status = $driver->deliveryStatus($participant, (string) $transmission->provider_reference, $transmission->document_type === 'CREDIT_NOTE');

                            if ($status !== null) {
                                $record($transmission, $status, error: $status === TransmissionStatus::Failed ? 'The access point reported a failed delivery.' : null);
                            }
                        });

                    $participant->forceFill(['last_polled_at' => now(), 'last_error' => null])->save();
                } catch (AccessPointFailed|ConnectionException $exception) {
                    $participant->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
                    $this->warn("Company `{$company->id}`: {$exception->getMessage()}");
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }

        $this->comment('Peppol sync done for '.count($ids).' companies.');

        return self::SUCCESS;
    }
}

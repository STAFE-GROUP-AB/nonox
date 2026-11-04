<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Console;

use App\Models\Company;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\AcceptPeppolDocument;
use App\Modules\Peppol\Actions\RecordTransmissionStatus;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\PeppolDocumentTooLarge;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Peppol\PeppolModule;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * The safety net under the webhook (ADR-050): for every company with an
 * account at the access point (module on or off), fetches documents that
 * have arrived, and while the module is on asks how documents sent in the
 * last 14 days fared.
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

        // Every company linked to the access point, whether the module is on or not: a document that arrives for a
        // company must never be left behind (it goes to quarantine when the company does not receive).
        $ids = $this->argument('company') !== null
            ? [(int) $this->argument('company')]
            : array_map('intval', CompanyScope::bypass(fn (): array => PeppolParticipant::query()->whereNotNull('provider_account')->orderBy('company_id')->pluck('company_id')->all()));
        $driver = AccessPoints::make();

        foreach ($ids as $id) {
            $company = Company::query()->find($id);

            if (! $company instanceof Company) {
                continue;
            }

            $this->info("Syncing company `{$company->id}`...");
            $moduleOn = $modules->isEnabled(PeppolModule::KEY, $company);

            CurrentCompany::run($company, function () use ($company, $driver, $accept, $record, $moduleOn): void {
                $participant = PeppolParticipant::query()->first();

                if (! $participant instanceof PeppolParticipant || ! $participant->canSend()) {
                    return;
                }

                try {
                    // Fetched whatever receiving is set to: a document addressed to the company is kept (in quarantine when it does not receive).
                    $held = false;

                    foreach ($driver->incoming($participant) as $document) {
                        try {
                            $accept($company, $document);
                        } catch (PeppolDocumentTooLarge) {
                            // Left at the access point: the window stays open so it is never passed by, and the alert is on the participant.
                            $held = true;
                            $participant->forceFill(['last_error' => __('En mottagen e-faktura är för stor för att tas emot och ligger kvar hos Peppol-operatören. Kontakta den som driftar NONOX.')])->save();
                            $this->error("Company `{$company->id}`: a received document is too large.");
                        }
                    }

                    // Delivery status only while the module is on; the documents sent stay as they are.
                    PeppolTransmission::query()
                        ->when(! $moduleOn, fn ($query) => $query->whereRaw('false'))
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

                    if (! $held) {
                        $participant->forceFill(['last_polled_at' => now(), 'last_error' => null])->save();
                    }
                } catch (InboxQuotaExceeded) {
                    // Polled again next time: last_polled_at is not moved, and what was taken is not taken twice.
                    $this->warn("Company `{$company->id}`: the inbox quota is used up for this hour.");
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

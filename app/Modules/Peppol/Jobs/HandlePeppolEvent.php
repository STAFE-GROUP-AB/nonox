<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Jobs;

use App\Models\Company;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\AcceptPeppolDocument;
use App\Modules\Peppol\Actions\RecordTransmissionStatus;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\PeppolDocumentTooLarge;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Domain\WebhookEvent;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Peppol\PeppolModule;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Acts on one verified webhook event inside the company it belongs to (the
 * company id is given explicitly, AGENTS.md §6.0): fetches a new incoming
 * document into the inbox, or records a delivery update on a document the
 * company sent. A reference that is not the company's is ignored.
 */
class HandlePeppolEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public readonly int $companyId,
        public readonly string $type,
        public readonly string $reference,
        public readonly string $documentType,
        public readonly ?string $status = null,
        public readonly ?string $error = null,
    ) {}

    /** Every release used up: the document stays at the access point and peppol:sync fetches it again. */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Peppol: an event could not be handled.', ['company_id' => $this->companyId, 'type' => $this->type, 'reference' => $this->reference]);
        Audit::record('peppol.event_failed', null, ['type' => $this->type, 'reference' => mb_substr($this->reference, 0, 120)], Company::query()->find($this->companyId));
    }

    public static function for(int $companyId, WebhookEvent $event): self
    {
        return new self($companyId, $event->type, $event->reference, $event->documentType, $event->status?->value, $event->error);
    }

    public function handle(ModuleManager $modules, AcceptPeppolDocument $accept, RecordTransmissionStatus $record): void
    {
        // Paused by the operator: the document stays at the access point and peppol:sync fetches it (and the status) later.
        if (AccessPoints::isPaused()) {
            return;
        }

        // A delivery update needs the module; an incoming document is taken anyway (into quarantine), never dropped.
        if ($this->type === WebhookEvent::DELIVERY && ! $modules->isEnabled(PeppolModule::KEY, $this->companyId)) {
            return;
        }

        $company = Company::query()->findOrFail($this->companyId);

        CurrentCompany::run($company, function () use ($company, $accept, $record): void {
            if ($this->type === WebhookEvent::DELIVERY) {
                $transmission = PeppolTransmission::query()->where('driver', AccessPoints::driver())->where('provider_reference', $this->reference)->first();
                $status = TransmissionStatus::tryFrom((string) $this->status);

                if ($transmission instanceof PeppolTransmission && $status !== null) {
                    $record($transmission, $status, error: $this->error);
                } elseif ($status !== null && $this->attempts() < 3) {
                    // The event can arrive before the sending job has stored the reference; peppol:sync is the net after that.
                    $this->release(60);
                }

                return;
            }

            $participant = PeppolParticipant::query()->first();

            // Fetched whatever receiving is set to: AcceptPeppolDocument keeps it in quarantine when the company does not receive.
            if (! $participant instanceof PeppolParticipant) {
                return;
            }

            try {
                $accept($company, AccessPoints::make()->fetch($participant, $this->reference, $this->documentType));
            } catch (PeppolDocumentTooLarge) {
                // Alerted; left at the access point for the operator (and the poll window stays open).
                $participant->forceFill(['last_error' => __('En mottagen e-faktura är för stor för att tas emot och ligger kvar hos Peppol-operatören. Kontakta den som driftar NONOX.')])->save();
            } catch (ConnectionException|InboxQuotaExceeded) {
                $this->release($this->backoff[min($this->attempts() - 1, 3)]);
            } catch (AccessPointFailed $exception) {
                if (! $exception->retryable) {
                    throw $exception;
                }

                $this->release($this->backoff[min($this->attempts() - 1, 3)]);
            }
        });
    }
}

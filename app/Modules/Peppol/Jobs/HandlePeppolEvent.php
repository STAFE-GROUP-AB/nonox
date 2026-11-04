<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Jobs;

use App\Models\Company;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\AcceptPeppolDocument;
use App\Modules\Peppol\Actions\RecordTransmissionStatus;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Domain\WebhookEvent;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Peppol\PeppolModule;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;

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

    public static function for(int $companyId, WebhookEvent $event): self
    {
        return new self($companyId, $event->type, $event->reference, $event->documentType, $event->status?->value, $event->error);
    }

    public function handle(ModuleManager $modules, AcceptPeppolDocument $accept, RecordTransmissionStatus $record): void
    {
        if (! $modules->isEnabled(PeppolModule::KEY, $this->companyId)) {
            return;
        }

        $company = Company::query()->findOrFail($this->companyId);

        CurrentCompany::run($company, function () use ($company, $accept, $record): void {
            if ($this->type === WebhookEvent::DELIVERY) {
                $transmission = PeppolTransmission::query()->where('driver', AccessPoints::driver())->where('provider_reference', $this->reference)->first();
                $status = TransmissionStatus::tryFrom((string) $this->status);

                if ($transmission instanceof PeppolTransmission && $status !== null) {
                    $record($transmission, $status, error: $this->error);
                }

                return;
            }

            $participant = PeppolParticipant::query()->first();

            if (! $participant instanceof PeppolParticipant || ! $participant->receive_enabled) {
                return;
            }

            try {
                $accept($company, AccessPoints::make()->fetch($participant, $this->reference, $this->documentType));
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

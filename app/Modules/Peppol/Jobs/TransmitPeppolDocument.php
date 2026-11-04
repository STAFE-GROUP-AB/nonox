<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Jobs;

use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\RecordTransmissionStatus;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\TransmissionStatus;
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
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Hands one queued document to the access point. The company id is given
 * explicitly (AGENTS.md §6.0). The same idempotency key (the transmission
 * id) goes with every attempt, so a retry after a time-out does not send
 * the invoice twice. A provider that is down or rate-limits is tried again
 * with growing pauses; a refused document fails at once.
 */
class TransmitPeppolDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public function __construct(
        public readonly int $companyId,
        public readonly string $transmissionId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 3 * 3600];
    }

    public function handle(ModuleManager $modules, RecordTransmissionStatus $record): void
    {
        if (! $modules->isEnabled(PeppolModule::KEY, $this->companyId)) {
            return;
        }

        CurrentCompany::run($this->companyId, function () use ($record): void {
            $transmission = PeppolTransmission::query()->find($this->transmissionId);

            if (! $transmission instanceof PeppolTransmission || $transmission->status !== TransmissionStatus::Queued) {
                return;
            }

            $participant = PeppolParticipant::query()->first();
            $transmission->increment('attempts');

            try {
                if (! $participant instanceof PeppolParticipant || ! $participant->canSend()) {
                    throw new AccessPointFailed('The company has no account with the access point.');
                }

                $xml = (string) Storage::disk(PeppolTransmission::DISK)->get($transmission->xml_path);

                if (hash('sha256', $xml) !== $transmission->xml_sha256) {
                    throw new AccessPointFailed('The stored document does not match its checksum.');
                }

                $reference = AccessPoints::make($transmission->driver)->send($participant, $xml, $transmission->document_type === 'CREDIT_NOTE', $transmission->id);
                $record($transmission, TransmissionStatus::Sent, $reference);
            } catch (ConnectionException $exception) {
                $this->retryOrFail($record, $transmission, new AccessPointFailed('The access point did not answer.', retryable: true));
            } catch (AccessPointFailed $exception) {
                $this->retryOrFail($record, $transmission, $exception);
            }
        });
    }

    /** After the last attempt: the transmission fails and can be sent again by a person. */
    public function failed(?Throwable $exception): void
    {
        CurrentCompany::run($this->companyId, function () use ($exception): void {
            $transmission = PeppolTransmission::query()->find($this->transmissionId);

            if ($transmission instanceof PeppolTransmission) {
                app(RecordTransmissionStatus::class)($transmission, TransmissionStatus::Failed, error: $exception instanceof AccessPointFailed ? $exception->getMessage() : 'The access point could not be reached.');
            }
        });
    }

    private function retryOrFail(RecordTransmissionStatus $record, PeppolTransmission $transmission, AccessPointFailed $exception): void
    {
        $transmission->forceFill(['last_error' => $exception->getMessage()])->save();

        if ($exception->retryable && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[min($this->attempts() - 1, 4)]);

            return;
        }

        $transmission->forceFill(['refused' => ! $exception->retryable])->save();
        $record($transmission, TransmissionStatus::Failed, error: $exception->getMessage());
    }
}

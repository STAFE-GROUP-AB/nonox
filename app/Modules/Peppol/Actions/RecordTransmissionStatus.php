<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Actions;

use App\Events\InvoiceSent;
use App\Models\Company;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Events\PeppolTransmissionChanged;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves a transmission forward: QUEUED → SENT → DELIVERED, or to FAILED.
 * A status never goes back (a late "sent" after "delivered" is ignored),
 * and a delivered document never becomes failed. Each change is audited
 * with ids only and announced (webhooks invoice.peppol_sent and
 * invoice.peppol_failed; reaching the access point is also invoice.sent).
 */
class RecordTransmissionStatus
{
    private const array ORDER = ['QUEUED' => 0, 'SENT' => 1, 'DELIVERED' => 2];

    public function __invoke(PeppolTransmission $transmission, TransmissionStatus $status, ?string $reference = null, ?string $error = null): PeppolTransmission
    {
        return DB::transaction(function () use ($transmission, $status, $reference, $error): PeppolTransmission {
            /** @var PeppolTransmission $transmission */
            $transmission = PeppolTransmission::query()->whereKey($transmission->id)->lockForUpdate()->firstOrFail();
            $current = $transmission->status;

            $moves = match (true) {
                $current === $status => false,
                $status === TransmissionStatus::Failed => $current !== TransmissionStatus::Delivered,
                $current === TransmissionStatus::Failed => $status === TransmissionStatus::Sent || $status === TransmissionStatus::Delivered,
                default => self::ORDER[$status->value] > self::ORDER[$current->value],
            };

            if (! $moves) {
                return $transmission;
            }

            $now = CarbonImmutable::now();
            $transmission->status = $status;
            $transmission->provider_reference ??= $reference;

            match ($status) {
                TransmissionStatus::Sent => $transmission->sent_at = $now,
                TransmissionStatus::Delivered => [$transmission->delivered_at = $now, $transmission->sent_at ??= $now],
                TransmissionStatus::Failed => [$transmission->failed_at = $now, $transmission->last_error = $error === null ? null : mb_substr($error, 0, 500)],
                TransmissionStatus::Queued => null,
            };

            $transmission->save();
            $company = Company::query()->findOrFail($transmission->company_id);

            Audit::record('invoice.peppol_'.strtolower($status->value), $transmission, [
                'invoice_id' => $transmission->invoice_id,
                'reference' => $transmission->provider_reference,
                'driver' => $transmission->driver,
            ], $company);

            event(new PeppolTransmissionChanged($company->id, $transmission->id, $status));

            if ($status === TransmissionStatus::Sent || ($status === TransmissionStatus::Delivered && $current === TransmissionStatus::Queued)) {
                event(new InvoiceSent($company->id, $transmission->invoice_id));
            }

            return $transmission;
        });
    }
}

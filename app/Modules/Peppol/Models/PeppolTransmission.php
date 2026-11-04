<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Models;

use App\Models\Invoice;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sending of an issued invoice or credit invoice through Peppol: the
 * document as handed to the access point (kept with its SHA-256) and what
 * became of it. The invoice itself never changes; this is a delivery
 * channel like e-mail (ADR-050).
 *
 * @property string $id
 * @property int $company_id
 * @property string $invoice_id
 * @property string $document_type INVOICE or CREDIT_NOTE
 * @property string $receiver
 * @property TransmissionStatus $status
 * @property string $driver
 * @property ?string $provider_reference
 * @property string $xml_path
 * @property string $xml_sha256
 * @property int $attempts
 * @property ?string $last_error
 * @property ?int $queued_by
 * @property CarbonImmutable $queued_at
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $delivered_at
 * @property ?CarbonImmutable $failed_at
 */
class PeppolTransmission extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const string DISK = 'local';

    /** @var list<string> */
    protected $fillable = ['company_id', 'invoice_id', 'document_type', 'receiver', 'status', 'driver', 'xml_path', 'xml_sha256', 'queued_by', 'queued_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TransmissionStatus::class,
            'attempts' => 'integer',
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

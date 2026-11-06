<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Models;

use App\Models\Invoice;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Jobs\TransmitPeppolDocument;
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
 * @property bool $refused the access point refused the document; a time-out is not a refusal
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
            'refused' => 'boolean',
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * What went wrong, in Swedish, for the person on the invoice page. The
     * provider's own text stays in last_error for the operator and is never
     * shown: it is in English and can name codes nobody can act on.
     */
    public function supportText(): ?string
    {
        if ($this->status === TransmissionStatus::Queued) {
            return match (true) {
                $this->last_error === TransmitPeppolDocument::PAUSED => (string) __('Väntar: Peppol är tillfälligt avstängt av den som driftar NONOX. E-fakturan skickas när Peppol är på igen.'),
                $this->last_error !== null => (string) __('Peppol-operatören svarade inte. NONOX försöker igen automatiskt med längre och längre mellanrum.'),
                default => null,
            };
        }

        if ($this->status !== TransmissionStatus::Failed) {
            return null;
        }

        return match (true) {
            $this->refused => (string) __('Peppol-operatören tog inte emot e-fakturan. Kontrollera kundens Peppol-ID och fakturans uppgifter och skicka igen. Kvarstår felet, kontakta den som driftar NONOX.'),
            $this->provider_reference !== null => (string) __('E-fakturan kom fram till Peppol-operatören, men mottagarens operatör tog inte emot den. Kontrollera Peppol-ID:t med kunden och skicka igen, eller skicka fakturan med e-post.'),
            default => (string) __('Peppol-operatören gick inte att nå. Skicka igen om en stund. Det blir samma e-faktura, så kunden får den inte två gånger.'),
        };
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payables\BankReasonCode;
use App\Domain\Payables\PaymentResponseStatus;
use App\Support\Iso20022\OutgoingPaymentEvent;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One answer from the bank about a payment out: accepted, rejected, booked
 * or returned, from a file or from NONOX Bank. What the bank said never
 * changes; the payment it was matched to and what was done are settled
 * once, while the answer is open. A database trigger backs this up.
 *
 * @property int $id
 * @property int $company_id
 * @property ?string $payment_response_import_id
 * @property ?int $bank_feed_transaction_id
 * @property string $kind
 * @property string $reference
 * @property ?string $original_message_id
 * @property ?string $payment_info_id
 * @property ?string $end_to_end_id
 * @property ?Money $amount_ore
 * @property ?string $currency
 * @property ?CarbonImmutable $date
 * @property ?string $creditor_account
 * @property ?string $creditor_name
 * @property ?string $reason_code
 * @property ?string $reason_text
 * @property ?string $match
 * @property ?string $batch_id
 * @property ?int $batch_item_id
 * @property PaymentResponseStatus $status
 * @property ?string $note
 * @property ?int $supplier_payment_id
 * @property ?CarbonImmutable $resolved_at
 * @property ?int $resolved_by
 * @property CarbonImmutable $created_at
 */
class PaymentResponse extends Model
{
    use BelongsToCompany;

    /** Matched on the references NONOX wrote in the payment file. */
    public const string BY_REFERENCE = 'REFERENCE';

    /** Matched on amount, date and the payee's account: a person confirms. */
    public const string BY_AMOUNT = 'AMOUNT';

    /** Why a person needs to look at an answer, by note code. */
    private const array NOTES = [
        'not_approved' => 'Betalfilen är inte godkänd i NONOX. Godkänn den först.',
        'amount_differs' => 'Banken drog ett annat belopp än det som stod i filen.',
        'already_paid' => 'Betalningen är redan bokförd. Om banken avvisade den, rätta bokföringen med en rättelseverifikation.',
        'item_rejected' => 'Banken har tidigare avvisat betalningen.',
        'returned' => 'Pengarna har kommit tillbaka. Rätta betalningen med en rättelseverifikation och betala fakturan igen.',
        'paid_elsewhere' => 'Fakturan har betalats på annat sätt sedan filen skapades.',
        'batch_closed' => 'Betalfilen är redan makulerad eller betald.',
        'not_found' => 'Ingen betalning i någon betalfil passar.',
        'currency' => 'Betalningen är i en annan valuta än kronor.',
        'foreign_whole' => 'Banken bokförde flera betalningar som ett belopp, men betalningar i utländsk valuta bokförs en och en med de kronor banken tog för just den. Läs in avisering per betalning.',
        'foreign_amount' => 'Beloppet banken drog stämmer inte med betalningen i utländsk valuta i filen (valuta, belopp eller kronor saknas).',
        'foreign_unconfirmed' => 'Banken uppgav inte beloppet i utländsk valuta. Kontrollera kronorna mot kontoutdraget och bekräfta.',
        'foreign_rate' => 'Kronorna avviker mer än 5 procent (lägst 500 och högst 5 000 kronor) från Riksbankens kurs på bokföringsdagen, eller från fakturans kurs. Kontrollera mot kontoutdraget och bekräfta.',
        'booking_failed' => 'Betalningen kunde inte bokföras automatiskt, till exempel för att perioden är låst. Bekräfta för att se varför.',
    ];

    private const array MUTABLE = ['match', 'batch_id', 'batch_item_id', 'status', 'note', 'supplier_payment_id', 'resolved_at', 'resolved_by', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'payment_response_import_id',
        'bank_feed_transaction_id',
        'kind',
        'reference',
        'original_message_id',
        'payment_info_id',
        'end_to_end_id',
        'amount_ore',
        'currency',
        'date',
        'creditor_account',
        'creditor_name',
        'reason_code',
        'reason_text',
        'instructed_currency',
        'instructed_amount_minor',
        'match',
        'batch_id',
        'batch_item_id',
        'status',
        'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_ore' => MoneyCast::class,
            'date' => 'immutable_date',
            'status' => PaymentResponseStatus::class,
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PaymentResponse $response): void {
            $original = PaymentResponseStatus::from((string) $response->getRawOriginal('status'));

            if (array_diff(array_keys($response->getDirty()), self::MUTABLE) !== [] || ! $original->isOpen()) {
                throw new LogicException("Payment response {$response->id} cannot be changed.");
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Payment responses cannot be removed.');
        });
    }

    /** "Avvisad", "Bokförd hos banken" … */
    public function kindLabel(): string
    {
        return match ($this->kind) {
            OutgoingPaymentEvent::ACCEPTED => __('Godkänd av banken'),
            OutgoingPaymentEvent::REJECTED => __('Avvisad av banken'),
            OutgoingPaymentEvent::RETURNED => __('Återförd av banken'),
            default => __('Dragen från kontot'),
        };
    }

    /** The bank's reason in Swedish, for rejections and returns. */
    public function reason(): ?string
    {
        if ($this->kind !== OutgoingPaymentEvent::REJECTED && $this->kind !== OutgoingPaymentEvent::RETURNED) {
            return null;
        }

        return BankReasonCode::describe($this->reason_code, $this->reason_text);
    }

    /** Why a person needs to look at the answer, in Swedish. */
    public function noteText(): ?string
    {
        $key = self::NOTES[$this->note ?? ''] ?? null;
        $text = $key === null ? null : __($key);

        return is_string($text) ? $text : null;
    }

    /** @return BelongsTo<SupplierPaymentBatchItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SupplierPaymentBatchItem::class, 'batch_item_id');
    }

    /** @return BelongsTo<SupplierPaymentBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SupplierPaymentBatch::class, 'batch_id');
    }

    /** @return BelongsTo<PaymentResponseImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(PaymentResponseImport::class, 'payment_response_import_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payables\PaymentBank;
use App\Domain\Payables\PaymentBatchStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A payment file sent to the bank: which invoices, how much, on which date.
 * The file and its contents never change. It is approved once before it may
 * be downloaded or confirmed (AGENTS.md §4); the batch is confirmed when the
 * bank has paid, or cancelled when it did not.
 *
 * @property string $id
 * @property int $company_id
 * @property int $number
 * @property PaymentBatchStatus $status
 * @property PaymentBank $bank
 * @property CarbonImmutable $execution_date
 * @property string $debtor_iban
 * @property string $debtor_bic
 * @property string $message_id
 * @property int $payment_count
 * @property Money $total_ore
 * @property string $file_path
 * @property string $file_sha256
 * @property int $created_by
 * @property ?CarbonImmutable $approved_at
 * @property ?int $approved_by
 * @property ?CarbonImmutable $confirmed_at
 * @property ?int $confirmed_by
 * @property ?CarbonImmutable $cancelled_at
 * @property ?int $cancelled_by
 */
class SupplierPaymentBatch extends Model
{
    use BelongsToCompany;
    use HasUuids;

    private const array MUTABLE = ['status', 'approved_at', 'approved_by', 'confirmed_at', 'confirmed_by', 'cancelled_at', 'cancelled_by', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'bank',
        'execution_date',
        'debtor_iban',
        'debtor_bic',
        'message_id',
        'payment_count',
        'total_ore',
        'file_path',
        'file_sha256',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'CREATED',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => PaymentBatchStatus::class,
            'bank' => PaymentBank::class,
            'execution_date' => 'immutable_date',
            'payment_count' => 'integer',
            'total_ore' => MoneyCast::class,
            'approved_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierPaymentBatch $batch): void {
            if (array_diff(array_keys($batch->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException("Payment batch {$batch->id} cannot be changed.");
            }

            if ($batch->getOriginal('approved_at') !== null && $batch->isDirty(['approved_at', 'approved_by'])) {
                throw new LogicException("Payment batch {$batch->id} is already approved.");
            }
        });

        static::deleting(function (SupplierPaymentBatch $batch): never {
            throw new LogicException("Payment batch {$batch->id} cannot be deleted.");
        });
    }

    public function isOpen(): bool
    {
        return $this->status === PaymentBatchStatus::Created;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Only an approved file goes to the bank. Batches confirmed before the
     * approval step existed have no approval but are paid history.
     */
    public function canBeDownloaded(): bool
    {
        return $this->isApproved() || $this->status === PaymentBatchStatus::Confirmed;
    }

    public function statusLabel(): string
    {
        if (! $this->isOpen()) {
            return $this->status->label();
        }

        return $this->isApproved()
            ? __('Godkänd, väntar på banken')
            : __('Väntar på godkännande');
    }

    /** "Betalfil 3" */
    public function title(): string
    {
        return __('Betalfil :number', ['number' => $this->number]);
    }

    public function filename(): string
    {
        return "betalfil-{$this->number}-{$this->execution_date->toDateString()}.xml";
    }

    /** @return HasMany<SupplierPaymentBatchItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierPaymentBatchItem::class, 'batch_id')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

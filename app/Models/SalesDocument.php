<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Invoicing\SalesDocumentStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An offert (quote) or order. Can be changed while it is open; a quote
 * becomes an order, and a quote or an order becomes a draft invoice.
 *
 * @property string $id
 * @property int $company_id
 * @property string $kind
 * @property int $number
 * @property int $customer_id
 * @property SalesDocumentStatus $status
 * @property CarbonImmutable $document_date
 * @property ?CarbonImmutable $until_date
 * @property ?string $your_reference
 * @property ?string $our_reference
 * @property ?string $notes
 * @property Money $net_ore
 * @property Money $vat_ore
 * @property Money $rounding_ore
 * @property Money $total_ore
 * @property ?string $from_quote_id
 * @property ?string $invoice_id
 * @property ?CarbonImmutable $sent_at
 * @property ?string $sent_to
 * @property ?int $created_by
 */
class SalesDocument extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const string QUOTE = 'QUOTE';

    public const string ORDER = 'ORDER';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'kind',
        'number',
        'customer_id',
        'document_date',
        'until_date',
        'your_reference',
        'our_reference',
        'notes',
        'from_quote_id',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'OPEN',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => SalesDocumentStatus::class,
            'document_date' => 'immutable_date',
            'until_date' => 'immutable_date',
            'net_ore' => MoneyCast::class,
            'vat_ore' => MoneyCast::class,
            'rounding_ore' => MoneyCast::class,
            'total_ore' => MoneyCast::class,
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function isQuote(): bool
    {
        return $this->kind === self::QUOTE;
    }

    public function isOpen(): bool
    {
        return $this->status === SalesDocumentStatus::Open;
    }

    public function title(): string
    {
        return $this->isQuote()
            ? __('Offert :number', ['number' => $this->number])
            : __('Order :number', ['number' => $this->number]);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<SalesDocumentRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(SalesDocumentRow::class)->orderBy('position');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function fromQuote(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'from_quote_id');
    }

    /** @return HasMany<SalesDocument, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(SalesDocument::class, 'from_quote_id');
    }
}

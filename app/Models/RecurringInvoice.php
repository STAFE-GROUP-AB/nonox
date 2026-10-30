<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Invoicing\AgreementStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoicing agreement (avtalsfakturering): the same rows invoiced to a
 * customer every interval_months, on invoice_day of the month.
 *
 * @property string $id
 * @property int $company_id
 * @property int $number
 * @property int $customer_id
 * @property string $name
 * @property int $interval_months
 * @property int $invoice_day
 * @property CarbonImmutable $next_invoice_date
 * @property ?CarbonImmutable $end_date
 * @property AgreementStatus $status
 * @property bool $send_automatically
 * @property ?string $your_reference
 * @property ?string $our_reference
 * @property ?string $notes
 * @property Money $net_ore
 * @property Money $total_ore
 * @property int $created_by
 */
class RecurringInvoice extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const array INTERVALS = [1, 3, 6, 12];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'customer_id',
        'name',
        'interval_months',
        'invoice_day',
        'next_invoice_date',
        'end_date',
        'send_automatically',
        'your_reference',
        'our_reference',
        'notes',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'ACTIVE',
        'net_ore' => 0,
        'total_ore' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'interval_months' => 'integer',
            'invoice_day' => 'integer',
            'next_invoice_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'status' => AgreementStatus::class,
            'send_automatically' => 'boolean',
            'net_ore' => MoneyCast::class,
            'total_ore' => MoneyCast::class,
        ];
    }

    /** "Avtal 3" */
    public function title(): string
    {
        return __('Avtal :number', ['number' => $this->number]);
    }

    public function intervalLabel(): string
    {
        return match ($this->interval_months) {
            1 => __('Varje månad'),
            3 => __('Varje kvartal'),
            6 => __('Varje halvår'),
            12 => __('Varje år'),
            default => __('Var :months:e månad', ['months' => $this->interval_months]),
        };
    }

    public function isDue(?CarbonImmutable $today = null): bool
    {
        return $this->status === AgreementStatus::Active
            && ! $this->next_invoice_date->greaterThan($today ?? CarbonImmutable::today());
    }

    /**
     * The invoice date after $date: interval_months later, on invoice_day or
     * the last day of a shorter month.
     */
    public function dateAfter(CarbonImmutable $date): CarbonImmutable
    {
        $month = $date->startOfMonth()->addMonthsNoOverflow($this->interval_months);

        return $month->setDay(min($this->invoice_day, $month->daysInMonth));
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<RecurringInvoiceRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(RecurringInvoiceRow::class)->orderBy('position');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

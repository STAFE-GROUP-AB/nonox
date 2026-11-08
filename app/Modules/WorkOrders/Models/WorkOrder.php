<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\WorkOrders\Domain\PriceForm;
use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An arbetsorder (ADR-074 §2): a job for a customer that collects time and
 * material and becomes the invoice basis. It creates no voucher.
 *
 * Access notes (door codes, key handover) are encrypted at rest, never in
 * the audit log, and removed when the work order is closed or cancelled
 * (AO-R39). The customer's personnummer is never copied here (AO-R38).
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $customer_id
 * @property string $title
 * @property ?string $description
 * @property ?string $sales_document_id
 * @property ?int $project_id
 * @property ?string $site_address_line1
 * @property ?string $site_postal_code
 * @property ?string $site_city
 * @property ?string $property_designation
 * @property ?string $housing_association_org_number
 * @property ?string $apartment_number
 * @property ?string $access_notes
 * @property PriceForm $price_form
 * @property ?Money $contract_sum_ore excluding VAT
 * @property ?Money $contract_labour_ore the labour part of the contract sum, excluding VAT
 * @property ?Money $approximate_price_ore including VAT (KtjL 36 §)
 * @property ?int $approximate_limit_bp another agreed limit than 15 %
 * @property int $material_markup_bp
 * @property int $vat_rate
 * @property ?array<int|string, int> $rates hourly or unit rate in öre per labour kind id
 * @property ?string $tax_reduction ROT or RUT
 * @property bool $reverse_charge
 * @property bool $is_construction
 * @property ?string $standard_contract
 * @property WorkOrderStatus $status
 * @property ?CarbonImmutable $planned_start
 * @property ?CarbonImmutable $planned_end
 * @property ?CarbonImmutable $done_at
 * @property ?CarbonImmutable $closed_at
 * @property ?int $created_by
 * @property ?CarbonImmutable $created_at
 */
class WorkOrder extends Model
{
    use BelongsToCompany;

    /** KtjL 36 §: an approximate price may be exceeded by at most 15 % unless another limit is agreed. */
    public const int DEFAULT_LIMIT_BP = 1500;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'customer_id',
        'title',
        'description',
        'sales_document_id',
        'project_id',
        'site_address_line1',
        'site_postal_code',
        'site_city',
        'property_designation',
        'housing_association_org_number',
        'apartment_number',
        'access_notes',
        'price_form',
        'contract_sum_ore',
        'contract_labour_ore',
        'approximate_price_ore',
        'approximate_limit_bp',
        'material_markup_bp',
        'vat_rate',
        'rates',
        'tax_reduction',
        'reverse_charge',
        'is_construction',
        'standard_contract',
        'planned_start',
        'planned_end',
        'created_by',
    ];

    /** @var list<string> */
    protected $hidden = ['access_notes'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'DRAFT',
        'material_markup_bp' => 0,
        'vat_rate' => 2500,
        'reverse_charge' => false,
        'is_construction' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'access_notes' => 'encrypted',
            'price_form' => PriceForm::class,
            'contract_sum_ore' => MoneyCast::class,
            'contract_labour_ore' => MoneyCast::class,
            'approximate_price_ore' => MoneyCast::class,
            'approximate_limit_bp' => 'integer',
            'material_markup_bp' => 'integer',
            'vat_rate' => 'integer',
            'rates' => 'array',
            'reverse_charge' => 'boolean',
            'is_construction' => 'boolean',
            'status' => WorkOrderStatus::class,
            'planned_start' => 'immutable_date',
            'planned_end' => 'immutable_date',
            'done_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function label(): string
    {
        return __('Arbetsorder :number', ['number' => $this->number]).' '.$this->title;
    }

    /** The rate in öre for a labour kind: the work order's own, else the kind's default. */
    public function rateFor(LabourKind $kind): int
    {
        return (int) (($this->rates ?? [])[$kind->id] ?? $kind->default_rate_ore->ore);
    }

    /** The limit the approximate price may be exceeded by, in basis points (KtjL 36 §, AO-R6). */
    public function limitBp(): int
    {
        return $this->approximate_limit_bp ?? self::DEFAULT_LIMIT_BP;
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_order_users')->withTimestamps();
    }

    /** @return HasMany<WorkOrderAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(WorkOrderAssignment::class);
    }

    /** @return HasMany<WorkOrderBuyer, $this> */
    public function buyers(): HasMany
    {
        return $this->hasMany(WorkOrderBuyer::class)->orderBy('id');
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** @return HasMany<MaterialEntry, $this> */
    public function materialEntries(): HasMany
    {
        return $this->hasMany(MaterialEntry::class);
    }

    /** @return HasMany<ChangeOrder, $this> */
    public function changeOrders(): HasMany
    {
        return $this->hasMany(ChangeOrder::class)->orderBy('number');
    }

    /** @return HasMany<PaymentInstalment, $this> */
    public function instalments(): HasMany
    {
        return $this->hasMany(PaymentInstalment::class)->orderBy('position');
    }

    /** @return HasMany<WorkOrderInvoice, $this> */
    public function invoiceLinks(): HasMany
    {
        return $this->hasMany(WorkOrderInvoice::class);
    }

    /** @return HasMany<WorkOrderPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(WorkOrderPhoto::class)->orderBy('id');
    }
}

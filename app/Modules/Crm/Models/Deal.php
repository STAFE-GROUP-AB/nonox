<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Crm\Domain\DealStatus;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A deal (ADR-043 stage 2): a sale being worked on with an account, its
 * value in öre excluding VAT, the stage it is in, when it is expected to
 * close and who owns it. An open deal always has a next step: an open
 * activity linked to it (DealNextStep). Won links, when someone chooses,
 * to the quote, order or invoice in the ledger; lost has a reason and a
 * date to try again.
 *
 * @property int $id
 * @property int $company_id
 * @property int $account_id
 * @property ?int $person_id
 * @property int $pipeline_id
 * @property int $stage_id
 * @property string $title
 * @property int $value_ore
 * @property ?CarbonImmutable $expected_close_date
 * @property ?int $owner_id
 * @property DealStatus $status
 * @property ?CarbonImmutable $won_at
 * @property ?CarbonImmutable $lost_at
 * @property ?int $loss_reason_id
 * @property ?string $loss_note
 * @property ?string $quote_id
 * @property ?string $order_id
 * @property ?string $invoice_id
 * @property ?CarbonImmutable $win_back_at
 * @property ?CarbonImmutable $win_back_task_at
 * @property ?int $created_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class Deal extends Model
{
    use BelongsToCompany;

    /** The largest value a deal may have, in öre (100 000 000 000 kr), like a goal's. */
    public const int MAX_VALUE_ORE = 10_000_000_000_000;

    protected $table = 'crm_deals';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'account_id',
        'person_id',
        'pipeline_id',
        'stage_id',
        'title',
        'value_ore',
        'expected_close_date',
        'owner_id',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'OPEN',
        'value_ore' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value_ore' => 'integer',
            'status' => DealStatus::class,
            'expected_close_date' => 'immutable_date',
            'won_at' => 'immutable_datetime',
            'lost_at' => 'immutable_datetime',
            'win_back_at' => 'immutable_date',
            'win_back_task_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<LossReason, $this> */
    public function lossReason(): BelongsTo
    {
        return $this->belongsTo(LossReason::class);
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'quote_id');
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'order_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** @return HasMany<DealStageChange, $this> */
    public function stageChanges(): HasMany
    {
        return $this->hasMany(DealStageChange::class);
    }

    public function isOpen(): bool
    {
        return $this->status === DealStatus::Open;
    }

    public function value(): Money
    {
        return Money::ofOre($this->value_ore);
    }
}

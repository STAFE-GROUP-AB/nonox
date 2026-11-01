<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\ImmutableVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A verifikation (AGENTS.md §6.4). Drafts can be edited freely; a posted
 * voucher is immutable at three levels: VoucherPolicy, the model events below
 * and PostgreSQL triggers.
 *
 * @property string $id
 * @property int $fiscal_year_id
 * @property int $series_id
 * @property ?int $number
 * @property VoucherStatus $status
 * @property CarbonImmutable $voucher_date
 * @property string $description
 * @property VoucherSource $source
 * @property ?string $corrects_voucher_id
 * @property ?string $corrected_by_voucher_id
 * @property ?CarbonImmutable $posted_at
 * @property ?int $posted_by
 * @property ?int $created_by
 * @property ?string $hash
 * @property ?string $previous_hash
 */
class Voucher extends Model
{
    use BelongsToCompany;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'series_id',
        'voucher_date',
        'description',
        'source',
        'source_type',
        'source_id',
        'corrects_voucher_id',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'DRAFT',
        'source' => 'MANUAL',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => VoucherStatus::class,
            'source' => VoucherSource::class,
            'voucher_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Voucher $voucher): void {
            if ($voucher->getOriginal('status') !== VoucherStatus::Posted) {
                return;
            }

            $changed = array_keys($voucher->getDirty());

            if (array_diff($changed, ['corrected_by_voucher_id', 'updated_at']) === []
                && $voucher->getOriginal('corrected_by_voucher_id') === null) {
                return;
            }

            throw ImmutableVoucher::cannotChange($voucher->id);
        });

        static::deleting(function (Voucher $voucher): void {
            if ($voucher->getOriginal('status') === VoucherStatus::Posted) {
                throw ImmutableVoucher::cannotDelete($voucher->id);
            }
        });
    }

    public function isPosted(): bool
    {
        return $this->status === VoucherStatus::Posted;
    }

    /**
     * "A12", or "Utkast" while the voucher has no number yet.
     */
    public function reference(): string
    {
        if ($this->number === null) {
            return __('Utkast');
        }

        $code = $this->relationLoaded('series') ? $this->series?->code : VoucherSeries::query()->whereKey($this->series_id)->value('code');

        return $code.$this->number;
    }

    /** @return HasMany<VoucherAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(VoucherAttachment::class)->orderBy('id');
    }

    /** @return HasMany<VoucherRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(VoucherRow::class)->orderBy('id');
    }

    /** @return HasOne<ImportedVoucher, $this> */
    public function importedFrom(): HasOne
    {
        return $this->hasOne(ImportedVoucher::class);
    }

    /**
     * The assets in the register whose purchase this voucher booked.
     *
     * @return HasMany<FixedAsset, $this>
     */
    public function acquiredAssets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'acquisition_voucher_id');
    }

    /** @return HasMany<VoucherRowHistory, $this> */
    public function rowHistory(): HasMany
    {
        return $this->hasMany(VoucherRowHistory::class)->orderBy('id');
    }

    /** @return BelongsTo<VoucherSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(VoucherSeries::class, 'series_id');
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /** @return BelongsTo<Voucher, $this> */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'corrects_voucher_id');
    }

    /** @return BelongsTo<Voucher, $this> */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'corrected_by_voucher_id');
    }
}

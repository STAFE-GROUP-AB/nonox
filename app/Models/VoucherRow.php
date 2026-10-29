<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\ImmutableVoucher;
use App\Domain\Accounting\VoucherStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a voucher: an account and either a debit or a credit amount in
 * whole öre (AGENTS.md §6.4).
 *
 * @property string $voucher_id
 * @property int $account_id
 * @property Money $debit_ore
 * @property Money $credit_ore
 * @property ?string $description
 * @property ?int $cost_center_id
 * @property ?int $project_id
 */
class VoucherRow extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'voucher_id',
        'account_id',
        'debit_ore',
        'credit_ore',
        'description',
        'cost_center_id',
        'project_id',
        'quantity',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'debit_ore' => MoneyCast::class,
            'credit_ore' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        $guard = function (VoucherRow $row): void {
            $voucherIds = array_unique(array_filter([$row->voucher_id, $row->getOriginal('voucher_id')]));

            if (Voucher::query()->whereIn('id', $voucherIds)->where('status', VoucherStatus::Posted->value)->exists()) {
                throw ImmutableVoucher::cannotChangeRows((string) $row->voucher_id);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Models\Voucher;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A payroll run for a period and payment date. Immutable once approved:
 * the policy and a database trigger refuse changes, and it only moves
 * forward in status.
 *
 * @property int $id
 * @property int $company_id
 * @property string $period
 * @property CarbonImmutable $payment_date
 * @property string $type
 * @property string $status
 * @property ?int $calculated_by
 * @property ?CarbonImmutable $calculated_at
 * @property ?int $approved_by
 * @property ?CarbonImmutable $approved_at
 * @property ?string $rule_snapshot_hash
 * @property ?string $posted_voucher_id
 * @property ?string $tax_voucher_id
 */
class PayrollRun extends Model
{
    use BelongsToCompany;

    public const string DRAFT = 'DRAFT';

    public const string CALCULATED = 'CALCULATED';

    public const string APPROVED = 'APPROVED';

    public const string PAID = 'PAID';

    public const string POSTED = 'POSTED';

    protected $table = 'payroll_runs';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'period',
        'payment_date',
        'type',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::DRAFT,
        'type' => 'REGULAR',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_date' => 'immutable_date',
            'calculated_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::DRAFT, self::CALCULATED], true);
    }

    public function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->period) ?: throw new LogicException("Bad period {$this->period}.");
    }

    public function periodEnd(): CarbonImmutable
    {
        return $this->periodStart()->endOfMonth()->startOfDay();
    }

    /** "Lön april 2026" */
    public function title(): string
    {
        return __('Lön :period', ['period' => $this->periodStart()->translatedFormat('F Y')]);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::DRAFT => __('Utkast'),
            self::CALCULATED => __('Beräknad, väntar på attest'),
            self::APPROVED => __('Attesterad'),
            'PAID' => __('Utbetald'),
            'POSTED' => __('Bokförd'),
            'REPORTED' => __('Deklarerad'),
            default => $this->status,
        };
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /** @return BelongsTo<Voucher, $this> the voucher that booked the tax and contributions against the tax account */
    public function taxVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'tax_voucher_id');
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'posted_voucher_id');
    }

    /** @return HasMany<PayrollInput, $this> */
    public function inputs(): HasMany
    {
        return $this->hasMany(PayrollInput::class);
    }
}

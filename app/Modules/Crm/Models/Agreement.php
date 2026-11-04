<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\RecurringInvoice;
use App\Models\User;
use App\Modules\Crm\Domain\AgreementKind;
use App\Modules\Crm\Domain\ValuePeriod;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agreement in the agreement register (ADR-046): with whom (an account),
 * what, from when to when, the notice period, whether it renews itself and
 * for how long, its value, the recurring invoice that bills it, and the
 * signed document. The current period ends on end_date; a renewing
 * agreement's end_date moves forward by renewal_months when it passes
 * (RenewAgreements), unless it has been terminated (terminated_on).
 *
 * @property int $id
 * @property int $company_id
 * @property int $account_id
 * @property string $title
 * @property AgreementKind $kind
 * @property CarbonImmutable $start_date
 * @property ?CarbonImmutable $end_date
 * @property int $notice_months
 * @property bool $auto_renew
 * @property ?int $renewal_months
 * @property int $value_ore
 * @property ValuePeriod $value_period
 * @property ?string $recurring_invoice_id
 * @property ?int $owner_id
 * @property ?string $body_text
 * @property ?string $notes
 * @property ?string $document_path
 * @property ?string $document_name
 * @property ?string $document_sha256
 * @property ?CarbonImmutable $terminated_on
 * @property ?CarbonImmutable $signed_at
 * @property ?CarbonImmutable $reminded_for_end_date
 * @property ?int $created_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class Agreement extends Model
{
    use BelongsToCompany;

    public const string ACTIVE = 'ACTIVE';

    public const string UPCOMING = 'UPCOMING';

    public const string TERMINATED = 'TERMINATED';

    public const string ENDED = 'ENDED';

    public const int MAX_VALUE_ORE = 10_000_000_000_000;

    protected $table = 'crm_agreements';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'account_id',
        'title',
        'kind',
        'start_date',
        'end_date',
        'notice_months',
        'auto_renew',
        'renewal_months',
        'value_ore',
        'value_period',
        'recurring_invoice_id',
        'owner_id',
        'body_text',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => AgreementKind::class,
            'value_period' => ValuePeriod::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'terminated_on' => 'immutable_date',
            'reminded_for_end_date' => 'immutable_date',
            'signed_at' => 'immutable_datetime',
            'notice_months' => 'integer',
            'renewal_months' => 'integer',
            'value_ore' => 'integer',
            'auto_renew' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Renews itself when the period ends: auto-renewal on, a renewal term, and not terminated. */
    public function renews(): bool
    {
        return $this->auto_renew && $this->terminated_on === null && (int) $this->renewal_months > 0;
    }

    /** The last day notice can be given for the current period, or null without an end date. */
    public function noticeDeadline(): ?CarbonImmutable
    {
        return $this->end_date?->subMonthsNoOverflow($this->notice_months);
    }

    public function status(CarbonImmutable $today): string
    {
        if ($this->start_date->greaterThan($today)) {
            return self::UPCOMING;
        }

        if ($this->end_date !== null && $this->end_date->lessThan($today) && ! $this->renews()) {
            return self::ENDED;
        }

        return $this->terminated_on !== null ? self::TERMINATED : self::ACTIVE;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::UPCOMING => __('Startar senare'),
            self::TERMINATED => __('Uppsagt'),
            self::ENDED => __('Avslutat'),
            default => __('Löpande'),
        };
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<RecurringInvoice, $this> */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /** @return HasMany<SigningRequest, $this> */
    public function signingRequests(): HasMany
    {
        return $this->hasMany(SigningRequest::class)->latest('id');
    }
}

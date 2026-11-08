<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningPdfs;
use App\Models\Company;
use App\Models\RecurringInvoice;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\AgreementKind;
use App\Modules\Crm\Domain\ValuePeriod;
use App\Support\Branding\Brand;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
class Agreement extends Model implements Signable
{
    use BelongsToCompany;

    public const string ACTIVE = 'ACTIVE';

    public const string UPCOMING = 'UPCOMING';

    public const string TERMINATED = 'TERMINATED';

    public const string ENDED = 'ENDED';

    public const string SIGNING_TYPE = 'AGREEMENT';

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

    public function signingType(): string
    {
        return self::SIGNING_TYPE;
    }

    public function signingKey(): string
    {
        return (string) $this->id;
    }

    public function signingCompanyId(): int
    {
        return $this->company_id;
    }

    public function signingTitle(): string
    {
        return $this->title;
    }

    /** The uploaded PDF, or one generated from the agreement's parties, term, notice, renewal, value and text. */
    public function signingDocument(): array
    {
        $name = "Avtal-{$this->id}.pdf";

        if ($this->document_path === null) {
            $this->loadMissing(['account']);
            $company = Company::query()->findOrFail($this->company_id);

            return ['bytes' => SigningPdfs::render(view('crm::pdf.agreement', [
                'agreement' => $this,
                'company' => $company,
                'brand' => Brand::for($company),
                'value' => Money::ofOre($this->value_ore),
            ])->render()), 'name' => $name];
        }

        $bytes = Storage::disk(SigningRequest::DISK)->get($this->document_path);

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('The agreement\'s document is missing.');
        }

        return ['bytes' => $bytes, 'name' => $name];
    }

    /** Its terms and its uploaded PDF's hash. */
    public function signingFingerprint(): string
    {
        $content = [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'title' => $this->title,
            'kind' => $this->kind->value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'notice_months' => $this->notice_months,
            'auto_renew' => $this->auto_renew,
            'renewal_months' => $this->renewal_months,
            'value_ore' => $this->value_ore,
            'value_period' => $this->value_period->value,
            'body_text' => $this->body_text,
            'document_sha256' => $this->document_path === null ? null : $this->document_sha256,
        ];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function signingIsOpen(): bool
    {
        return $this->signed_at === null;
    }

    public function signingAcceptance(): string
    {
        $account = Account::query()->find($this->account_id);

        return $account instanceof Account && $account->kind === AccountKind::Company
            ? __('Jag har läst och godkänner avtalet och har rätt att ingå det för :account.', ['account' => $account->name])
            : __('Jag har läst och godkänner avtalet.');
    }

    public function signingModule(): ?string
    {
        return CrmModule::KEY;
    }

    public function signingValidUntil(): ?CarbonImmutable
    {
        return null;
    }

    public function signingDestination(): string
    {
        return 'crm-agreements';
    }

    public function authorizeSigning(User $user, bool $revoke = false): void
    {
        Gate::forUser($user)->authorize('update', $this);
    }

    public function markSigned(CarbonImmutable $signedAt, string $documentSha256): void
    {
        self::query()->whereKey($this->id)->update(['signed_at' => $signedAt, 'document_sha256' => $documentSha256]);
    }
}

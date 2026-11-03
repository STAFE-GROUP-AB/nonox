<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a company or a waiting-list entry ended up in the operator's
 * registers (ADR-044): the customer, CRM account and CRM person in the
 * target company, whether the sync created them (and so may erase them
 * again), and SHA-256 hashes of the values last pushed, so a later change
 * in the source updates only the fields that changed there and leaves the
 * operator's own edits alone.
 *
 * Instance data that points into a company: no BelongsToCompany, only HQ
 * and the sync read it, and every write into the target goes through
 * CurrentCompany::run and the target's own Actions.
 *
 * @property int $id
 * @property int $target_company_id
 * @property ?int $source_company_id
 * @property ?int $waitlist_entry_id
 * @property ?int $customer_id
 * @property ?int $crm_account_id
 * @property ?int $crm_person_id
 * @property bool $customer_created
 * @property bool $account_created
 * @property bool $person_created
 * @property string $status
 * @property ?int $review_customer_id
 * @property ?int $review_account_id
 * @property ?int $review_person_id
 * @property ?string $review_decision
 * @property ?array<string, string> $pushed
 * @property ?string $error
 * @property ?CarbonImmutable $failed_at
 * @property ?CarbonImmutable $synced_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class OperatorLink extends Model
{
    public const string PENDING = 'PENDING';

    public const string SYNCED = 'SYNCED';

    /** The contact details are on the target's suppression list, or the person there must not be contacted: nothing was created. */
    public const string SUPPRESSED = 'SUPPRESSED';

    public const string FAILED = 'FAILED';

    /**
     * The operator already has a customer, CRM account or person with the
     * same organisation number or e-mail that the sync did not create:
     * nothing is linked or written until an HQ admin decides.
     */
    public const string NEEDS_REVIEW = 'NEEDS_REVIEW';

    /** HQ's decision on a NEEDS_REVIEW link: link to the existing record. */
    public const string DECISION_LINK = 'LINK';

    /** HQ's decision on a NEEDS_REVIEW link: create a new record. */
    public const string DECISION_NEW = 'NEW';

    /** @var list<string> */
    protected $fillable = [
        'target_company_id',
        'source_company_id',
        'waitlist_entry_id',
        'customer_id',
        'crm_account_id',
        'crm_person_id',
        'customer_created',
        'account_created',
        'person_created',
        'status',
        'review_customer_id',
        'review_account_id',
        'review_person_id',
        'review_decision',
        'pushed',
        'error',
        'failed_at',
        'synced_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::PENDING,
        'customer_created' => false,
        'account_created' => false,
        'person_created' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_created' => 'boolean',
            'account_created' => 'boolean',
            'person_created' => 'boolean',
            'pushed' => 'array',
            'failed_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function sourceCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'source_company_id');
    }

    /** @return BelongsTo<WaitlistEntry, $this> */
    public function waitlistEntry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class);
    }
}

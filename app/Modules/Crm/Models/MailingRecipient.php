<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person a mailing goes to (ADR-048). The token in the recipient's
 * links (unsubscribe and clicks) is random and only its SHA-256 is kept.
 * The address is removed 90 days after the send and the row after 13
 * months (crm:prune-mail); the mailing keeps its counts.
 *
 * @property int $id
 * @property int $company_id
 * @property int $mailing_id
 * @property ?int $person_id
 * @property ?int $account_id the account that put the person in the segment
 * @property ?string $email
 * @property string $token_hash
 * @property string $status QUEUED, SENT, FAILED, BOUNCED, SKIPPED or CANCELLED
 * @property ?string $skip_reason
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $delivered_at
 * @property ?CarbonImmutable $clicked_at
 * @property ?CarbonImmutable $unsubscribed_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class MailingRecipient extends Model
{
    use BelongsToCompany;

    public const string QUEUED = 'QUEUED';

    public const string SENT = 'SENT';

    public const string FAILED = 'FAILED';

    public const string BOUNCED = 'BOUNCED';

    public const string SKIPPED = 'SKIPPED';

    public const string CANCELLED = 'CANCELLED';

    protected $table = 'crm_mailing_recipients';

    /** @var list<string> */
    protected $fillable = ['company_id', 'mailing_id', 'person_id', 'account_id', 'email', 'token_hash', 'status', 'skip_reason', 'sent_at', 'delivered_at', 'clicked_at', 'unsubscribed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'clicked_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Mailing, $this> */
    public function mailing(): BelongsTo
    {
        return $this->belongsTo(Mailing::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}

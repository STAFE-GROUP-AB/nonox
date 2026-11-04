<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person a mailing goes to (ADR-048). The token in the recipient's
 * unsubscribe link is an HMAC and only its SHA-256 is kept; it is never
 * used for statistics. After 90 days the address and the person give way
 * to the suppression list's hash of the address (a stub that keeps the
 * unsubscribe link working), and after three years the row goes
 * (crm:prune-mail); the mailing keeps its counts.
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
 * @property ?string $email_hash the suppression list's hash of the address, kept instead of it after 90 days
 * @property ?CarbonImmutable $complained_at
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
    protected $fillable = ['company_id', 'mailing_id', 'person_id', 'account_id', 'email', 'token_hash', 'status', 'skip_reason', 'sent_at', 'delivered_at', 'unsubscribed_at', 'email_hash', 'complained_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'complained_at' => 'immutable_datetime',
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

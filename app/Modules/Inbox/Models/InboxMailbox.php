<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The company's own receiving address ({slug}@in.nonox.app) and, for a
 * self-hosted installation, where fetching from its IMAP mailbox has got to.
 *
 * @property int $id
 * @property int $company_id
 * @property string $slug
 * @property ?CarbonImmutable $slug_changed_at
 * @property int $imap_last_uid
 * @property ?CarbonImmutable $imap_last_fetched_at
 * @property ?string $imap_last_error
 * @property ?CarbonImmutable $notified_at
 * @property ?CarbonImmutable $summary_sent_on
 */
class InboxMailbox extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'slug', 'slug_changed_at', 'imap_last_uid', 'imap_last_fetched_at', 'imap_last_error', 'notified_at', 'summary_sent_on'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'slug_changed_at' => 'immutable_datetime',
            'imap_last_uid' => 'integer',
            'imap_last_fetched_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'summary_sent_on' => 'immutable_date',
        ];
    }
}

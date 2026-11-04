<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Domain\CallOutcome;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account on a call list (ADR-047): its place in the queue, how many
 * times it has been called, and the last call's outcome and activity.
 *
 * @property int $id
 * @property int $company_id
 * @property int $call_list_id
 * @property int $account_id
 * @property int $position
 * @property string $status PENDING, DONE or SKIPPED
 * @property int $attempts
 * @property ?CallOutcome $outcome
 * @property ?int $activity_id
 * @property ?CarbonImmutable $done_at
 * @property ?int $done_by
 */
class CallListItem extends Model
{
    use BelongsToCompany;

    public const string PENDING = 'PENDING';

    public const string DONE = 'DONE';

    public const string SKIPPED = 'SKIPPED';

    protected $table = 'crm_call_list_items';

    /** @var list<string> */
    protected $fillable = ['company_id', 'call_list_id', 'account_id', 'position', 'status', 'attempts', 'outcome', 'activity_id', 'done_at', 'done_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING, 'attempts' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'attempts' => 'integer',
            'outcome' => CallOutcome::class,
            'done_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CallList, $this> */
    public function callList(): BelongsTo
    {
        return $this->belongsTo(CallList::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

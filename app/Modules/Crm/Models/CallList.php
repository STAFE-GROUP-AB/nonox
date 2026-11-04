<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A call list (ringlista, ADR-047): accounts to call one at a time, for one
 * seller, built from a prospect search or from the CRM's own accounts.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property ?int $assigned_to
 * @property ?int $created_by
 * @property ?array<string, mixed> $criteria
 * @property ?CarbonImmutable $archived_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class CallList extends Model
{
    use BelongsToCompany;

    /** The most accounts one list holds. */
    public const int MAX_ITEMS = 500;

    /** "Inget svar" and "Lämnade meddelande" put a company back in the queue until this many calls. */
    public const int MAX_ATTEMPTS = 3;

    protected $table = 'crm_call_lists';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'assigned_to', 'created_by', 'criteria', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CallListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CallListItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}

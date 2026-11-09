<?php

declare(strict_types=1);

namespace App\Modules\Portal\Models;

use App\Models\Customer;
use App\Modules\Portal\Domain\ThreadKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question from a customer's contact to the company, or a dispute of an
 * invoice (ADR-075 §6). Kept, unlike the chat: a closed thread is pruned 24
 * months after it closed, unless it is a dispute or the company keeps it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $customer_id
 * @property ?int $contact_id
 * @property ?string $subject_type INVOICE or QUOTE
 * @property ?string $subject_id
 * @property string $subject
 * @property ThreadKind $kind
 * @property string $status OPEN or CLOSED
 * @property bool $keep
 * @property bool $answered whether the company has written last
 * @property CarbonImmutable $last_message_at
 * @property ?CarbonImmutable $closed_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class PortalThread extends Model
{
    use BelongsToCompany;

    public const string OPEN = 'OPEN';

    public const string CLOSED = 'CLOSED';

    public const string INVOICE = 'INVOICE';

    public const string QUOTE = 'QUOTE';

    protected $table = 'customer_portal_threads';

    /** @var list<string> */
    protected $fillable = ['company_id', 'customer_id', 'contact_id', 'subject_type', 'subject_id', 'subject', 'kind', 'last_message_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'OPEN', 'kind' => 'QUESTION', 'keep' => false, 'answered' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ThreadKind::class,
            'keep' => 'boolean',
            'answered' => 'boolean',
            'last_message_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<PortalContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(PortalContact::class, 'contact_id');
    }

    /** @return HasMany<PortalMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(PortalMessage::class, 'thread_id')->orderBy('id');
    }
}

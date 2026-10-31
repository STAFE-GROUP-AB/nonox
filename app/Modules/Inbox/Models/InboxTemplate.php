<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A template learned when a person approved a proposal: the sender, the
 * document's format fingerprint and how its fields map to accounts. The next
 * document from the same sender with the same fingerprint is read with the
 * template, without AI. Automatic booking is the person's own choice per
 * template and only ever happens on an exact match (ADR-023).
 *
 * @property int $id
 * @property int $company_id
 * @property string $sender
 * @property string $kind
 * @property string $format
 * @property string $fingerprint
 * @property string $name
 * @property array<string, mixed> $mapping
 * @property bool $auto_book
 * @property ?int $auto_book_user_id
 * @property ?int $approved_by
 * @property int $uses
 * @property ?CarbonImmutable $last_used_at
 * @property ?CarbonImmutable $updated_at
 * @property-read ?User $autoBookUser
 */
class InboxTemplate extends Model
{
    use BelongsToCompany;

    public const string DAILY_REPORT = 'DAILY_REPORT';

    public const string SUPPLIER_INVOICE = 'SUPPLIER_INVOICE';

    /** @var list<string> */
    protected $fillable = ['company_id', 'sender', 'kind', 'format', 'fingerprint', 'name', 'mapping', 'auto_book', 'auto_book_user_id', 'approved_by', 'uses', 'last_used_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'auto_book' => 'boolean',
            'uses' => 'integer',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function autoBookUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auto_book_user_id');
    }
}

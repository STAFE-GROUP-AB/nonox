<?php

declare(strict_types=1);

namespace App\Modules\Portal\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message in a portal thread (ADR-075 §6): plain text, at most 4 000
 * characters, from the contact or from a company user. When the contact is
 * removed the text stays with the author shown as "Borttagen kontakt".
 *
 * @property int $id
 * @property int $company_id
 * @property int $thread_id
 * @property ?int $contact_id
 * @property ?int $user_id
 * @property string $author_kind CONTACT or USER
 * @property string $body
 * @property CarbonImmutable $created_at
 */
class PortalMessage extends Model
{
    use BelongsToCompany;

    public const string CONTACT = 'CONTACT';

    public const string USER = 'USER';

    public const int MAX_LENGTH = 4000;

    public const null UPDATED_AT = null;

    protected $table = 'customer_portal_messages';

    /** @var list<string> */
    protected $fillable = ['company_id', 'thread_id', 'contact_id', 'user_id', 'author_kind', 'body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function fromContact(): bool
    {
        return $this->author_kind === self::CONTACT;
    }

    /** @return BelongsTo<PortalThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(PortalThread::class, 'thread_id');
    }

    /** @return BelongsTo<PortalContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(PortalContact::class, 'contact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PortalAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(PortalAttachment::class, 'message_id')->orderBy('id');
    }
}

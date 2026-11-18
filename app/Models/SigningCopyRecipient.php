<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who gets a copy of an envelope's document (ADR-046 amendment, gap
 * 1): no link and nothing to sign. They get the document and the signing
 * certificates when every signer has signed and, if the sender ticked it,
 * a notice when it is sent. The address never changes (PostgreSQL trigger).
 *
 * @property int $id
 * @property int $company_id
 * @property string $envelope_id
 * @property string $email
 * @property ?string $name
 * @property ?CarbonImmutable $notified_at
 * @property ?CarbonImmutable $sent_copy_at
 */
class SigningCopyRecipient extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'envelope_id',
        'email',
        'name',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notified_at' => 'immutable_datetime',
            'sent_copy_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SigningEnvelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SigningEnvelope::class, 'envelope_id');
    }
}

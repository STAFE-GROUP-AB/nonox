<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * What a signer filled in for one of their fields (ADR-046 amendment
 * 2026-11-18, gap 2, package S6): a text value of at most 500 characters,
 * or a drawn signature or initials (a PNG, encrypted). Written once, in the
 * transaction that signs; its hash is in the envelope's chain
 * (FIELDS_FILLED). Never changed, and removed only with its envelope
 * (PostgreSQL trigger).
 *
 * @property int $id
 * @property int $company_id
 * @property int $field_id
 * @property int $signing_request_id
 * @property ?string $value
 * @property ?string $image base64 PNG
 * @property CarbonImmutable $filled_at
 */
class SigningFieldValue extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /** Times are written with their offset, like the rest of the signing evidence (ADR-046 S2). */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'field_id',
        'signing_request_id',
        'value',
        'image',
        'filled_at',
    ];

    /** @var list<string> */
    protected $hidden = ['image'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'image' => 'encrypted',
            'filled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('A signing field value never changes (ADR-046 S6).'));
    }

    /** @return BelongsTo<SigningField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(SigningField::class, 'field_id');
    }
}

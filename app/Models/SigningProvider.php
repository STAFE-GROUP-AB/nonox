<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Signing\Providers\SignatureLevel;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A company's external signing service (ADR-046 amendment, gap 12, package
 * S17): a remote signing service with the Cloud Signature Consortium API v2
 * (driver "csc"), later BankID. Added under Inställningar för företaget →
 * Signering by a member with signing:settings.
 *
 * The OAuth client id and secret are encrypted at rest and hidden from every
 * array or JSON form of the row; they are decrypted only when a signer signs.
 * claimed_level is what the company states the service gives under its
 * agreement with it (simple, advanced or qualified electronic signature):
 * NONOX repeats it as the provider's claim, never as its own (principle 4).
 * A configuration is replaced, never edited: retiring it empties the secret
 * (PostgreSQL trigger).
 *
 * @property int $id
 * @property int $company_id
 * @property string $driver
 * @property string $name
 * @property string $base_url
 * @property ?string $oauth_url
 * @property string $client_id
 * @property ?string $client_secret
 * @property SignatureLevel $claimed_level
 * @property ?string $service_name
 * @property ?string $service_region
 * @property ?int $created_by
 * @property ?CarbonImmutable $retired_at
 * @property ?int $retired_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class SigningProvider extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'driver',
        'name',
        'base_url',
        'oauth_url',
        'client_id',
        'client_secret',
        'claimed_level',
        'service_name',
        'service_region',
        'created_by',
    ];

    /** @var list<string> never in an array, JSON, API answer or Livewire payload */
    protected $hidden = ['client_id', 'client_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'claimed_level' => SignatureLevel::class,
            'retired_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $provider): void {
            if (array_diff(array_keys($provider->getDirty()), ['retired_at', 'retired_by', 'client_secret', 'updated_at', 'created_by']) !== []) {
                throw new LogicException('A signing provider cannot be changed, only retired (ADR-046 S17).');
            }
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }
}

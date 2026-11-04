<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Models;

use App\Modules\Peppol\Domain\RegistrationStatus;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The company on the Peppol network: its own participant identifier, its
 * account with the installation's access-point provider and whether it
 * receives invoices (ADR-050).
 *
 * @property int $id
 * @property int $company_id
 * @property string $identifier
 * @property bool $receive_enabled
 * @property ?string $provider
 * @property ?string $provider_account
 * @property ?string $webhook_id
 * @property ?string $webhook_secret the signing secret of the provider's webhook subscription, encrypted at rest
 * @property RegistrationStatus $registration_status
 * @property ?CarbonImmutable $registered_at
 * @property ?int $consent_by
 * @property ?CarbonImmutable $consent_at
 * @property ?string $last_error
 * @property ?CarbonImmutable $last_polled_at
 */
class PeppolParticipant extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'identifier', 'receive_enabled'];

    /** @var list<string> */
    protected $hidden = ['webhook_secret'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'receive_enabled' => false,
        'registration_status' => 'NOT_REGISTERED',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'receive_enabled' => 'boolean',
            'webhook_secret' => 'encrypted',
            'registration_status' => RegistrationStatus::class,
            'registered_at' => 'immutable_datetime',
            'consent_at' => 'immutable_datetime',
            'last_polled_at' => 'immutable_datetime',
        ];
    }

    public function participant(): PeppolIdentifier
    {
        return PeppolIdentifier::tryFrom($this->identifier) ?? throw new LogicException("Stored Peppol identifier {$this->id} is not valid.");
    }

    /** Linked to an account with the access-point provider, so documents can go out. */
    public function canSend(): bool
    {
        return $this->provider_account !== null;
    }

    public function canReceive(): bool
    {
        return $this->receive_enabled && $this->provider_account !== null && $this->registration_status === RegistrationStatus::Registered;
    }
}

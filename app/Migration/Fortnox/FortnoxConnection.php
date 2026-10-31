<?php

declare(strict_types=1);

namespace App\Migration\Fortnox;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's connection to its Fortnox account during a move. The tokens
 * are encrypted and cleared when the move is marked complete (AGENTS.md
 * §14.1). progress holds what has been done: SIE imports per Fortnox
 * financial year and the result of the register import.
 *
 * @property int $id
 * @property int $company_id
 * @property ?string $access_token
 * @property ?string $refresh_token
 * @property ?CarbonImmutable $expires_at
 * @property ?string $scope
 * @property ?string $fortnox_company_name
 * @property ?string $fortnox_org_number
 * @property array<string, mixed> $progress
 * @property int $connected_by
 * @property ?CarbonImmutable $completed_at
 */
class FortnoxConnection extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scope',
        'fortnox_company_name',
        'fortnox_org_number',
        'progress',
        'connected_by',
        'completed_at',
    ];

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'progress' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function isConnected(): bool
    {
        return $this->refresh_token !== null && $this->completed_at === null;
    }
}

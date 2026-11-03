<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One setting of the installation (ADR-044), edited in /hq. Instance data,
 * not company data: no company_id and no BelongsToCompany. Read through
 * App\Support\Instance\InstanceSettings, never directly.
 *
 * @property string $key
 * @property mixed $value
 * @property ?int $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class InstanceSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['key', 'value', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

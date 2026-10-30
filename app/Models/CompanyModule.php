<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A module's state and settings for one company.
 *
 * @property int $company_id
 * @property string $module_key
 * @property bool $enabled
 * @property ?CarbonImmutable $enabled_at
 * @property ?int $enabled_by
 * @property ?CarbonImmutable $disabled_at
 * @property ?int $disabled_by
 * @property array<string, mixed> $settings
 */
class CompanyModule extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'module_key',
        'enabled',
        'settings',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => false,
        'settings' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'settings' => 'array',
        ];
    }
}

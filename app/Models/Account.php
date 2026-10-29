<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\AccountType;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An account in a company's chart of accounts (AGENTS.md §6.1).
 *
 * @property int $number
 * @property string $name
 * @property AccountType $type
 * @property bool $is_active
 */
class Account extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'name',
        'type',
        'sru_code',
        'is_active',
        'requires_cost_center',
        'requires_project',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'type' => AccountType::class,
            'is_active' => 'boolean',
            'requires_cost_center' => 'boolean',
            'requires_project' => 'boolean',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A kostnadsställe (cost centre), for example a restaurant or a department
 * (AGENTS.md §6.5, SIE dimension 1).
 * The code never changes; the object can be renamed and deactivated.
 *
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
class CostCenter extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function label(): string
    {
        return "{$this->code} {$this->name}";
    }
}

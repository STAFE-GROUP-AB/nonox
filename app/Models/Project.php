<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A projekt (AGENTS.md §6.5, SIE dimension 6).
 * The code never changes; the object can be renamed and deactivated.
 *
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property ?int $owner_user_id who can approve for it (ADR-081 gap 8)
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class Project extends Model
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

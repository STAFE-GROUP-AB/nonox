<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A column mapping the company saved for a register kind ("Mitt format",
 * ADR-076). Header names and field names only, never data.
 *
 * @property int $company_id
 * @property string $kind
 * @property string $name
 * @property array<string, string> $mapping NONOX field => source header
 */
class RegisterImportPreset extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'kind', 'name', 'mapping'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mapping' => 'array'];
    }
}

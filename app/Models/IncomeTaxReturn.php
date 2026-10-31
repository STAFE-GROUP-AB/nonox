<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The parts of an INK2 return a person enters; see App\Domain\IncomeTax\Ink2.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property array<int, int> $adjustments whole kronor by field code
 * @property ?bool $consultant
 * @property ?bool $audited
 * @property int $updated_by
 */
class IncomeTaxReturn extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'adjustments',
        'consultant',
        'audited',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'adjustments' => 'array',
            'consultant' => 'boolean',
            'audited' => 'boolean',
        ];
    }
}

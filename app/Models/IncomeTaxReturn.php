<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The parts of an income tax return a person enters, one row per fiscal
 * year and form: INK2 (App\Domain\IncomeTax\Ink2), NE (Ne) or INK4 (Ink4).
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property string $form INK2, NE or INK4
 * @property array<int, int> $adjustments whole kronor by field code
 * @property ?array<string, mixed> $inputs what the owner confirms for NONOX's calculations, never personal data
 * @property ?array<string, string> $personal personal data a calculation needs (the owner's century of birth), encrypted
 * @property ?bool $consultant
 * @property ?bool $audited
 * @property int $updated_by
 */
class IncomeTaxReturn extends Model
{
    use BelongsToCompany;

    public const string INK2 = 'INK2';

    public const string NE = 'NE';

    public const string INK4 = 'INK4';

    /** @var list<string> */
    protected $hidden = ['personal'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'form',
        'adjustments',
        'inputs',
        'personal',
        'consultant',
        'audited',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'adjustments' => 'array',
            'inputs' => 'array',
            'personal' => 'encrypted:array',
            'consultant' => 'boolean',
            'audited' => 'boolean',
        ];
    }
}

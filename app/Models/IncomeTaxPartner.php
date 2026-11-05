<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A partner in a handelsbolag or kommanditbolag for a fiscal year, for
 * INK4DU (ADR-053): the BAS equity block (position 1 = 2010–2019 …), the
 * share of the result in basis points, and the identity Skatteverket needs,
 * a personnummer or organisationsnummer, encrypted at rest.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property int $position
 * @property string $name
 * @property string $identity twelve digits: 19/20 and a personnummer, or 16 and an organisationsnummer
 * @property bool $legal_person
 * @property int $share_bp
 * @property int $updated_by
 */
class IncomeTaxPartner extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'position',
        'name',
        'identity',
        'legal_person',
        'share_bp',
        'updated_by',
    ];

    /** @var list<string> */
    protected $hidden = ['identity'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'identity' => 'encrypted',
            'legal_person' => 'boolean',
            'position' => 'integer',
            'share_bp' => 'integer',
        ];
    }
}

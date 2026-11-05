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
 * @property ?int $contributions_kronor 8103 entered, else from the books
 * @property ?int $withdrawals_kronor 8104 entered, else from the books
 * @property ?int $employer_deduction_kronor 8105, the share of a regional nedsättning
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
        'contributions_kronor',
        'withdrawals_kronor',
        'employer_deduction_kronor',
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
            'contributions_kronor' => 'integer',
            'withdrawals_kronor' => 'integer',
            'employer_deduction_kronor' => 'integer',
        ];
    }
}

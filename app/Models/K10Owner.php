<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One owner's K10 underlag for one inkomstår (ADR-062): the name, the
 * personnummer only when the owner wants an SRU file (encrypted, hidden),
 * an optional link to the owner's payroll employee for the own cash pay, the
 * spouse among the owners, the shares at the start of the year, and what
 * only the owner knows (omkostnadsbelopp, sparat utdelningsutrymme, holdings
 * in other fåmansföretag). Amounts in whole kronor; null means "use the
 * books' proposal" or "nothing entered".
 *
 * @property int $id
 * @property int $company_id
 * @property int $income_year
 * @property string $name
 * @property ?string $identity twelve digits, 19/20 and a personnummer
 * @property ?int $employee_id
 * @property ?int $spouse_owner_id
 * @property int $shares_owned
 * @property ?CarbonImmutable $acquired_on
 * @property bool $topped_up_previous_year held shares all of the year before and bought more during it (two calculations, refused)
 * @property ?int $other_holdings_bp
 * @property ?int $cost_basis_kronor
 * @property ?int $saved_space_kronor
 * @property bool $saved_space_carried the saved space was carried over from last year's underlag, not entered
 * @property ?int $own_pay_kronor
 * @property ?int $related_pay_kronor
 * @property ?int $company_pay_kronor
 * @property ?int $subsidiary_pay_kronor
 * @property ?int $dividend_kronor
 * @property bool $transferred_shares
 * @property int $updated_by
 */
class K10Owner extends Model
{
    use BelongsToCompany;

    protected $table = 'k10_owners';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'income_year',
        'name',
        'identity',
        'employee_id',
        'spouse_owner_id',
        'shares_owned',
        'acquired_on',
        'topped_up_previous_year',
        'other_holdings_bp',
        'cost_basis_kronor',
        'saved_space_kronor',
        'saved_space_carried',
        'own_pay_kronor',
        'related_pay_kronor',
        'company_pay_kronor',
        'subsidiary_pay_kronor',
        'dividend_kronor',
        'transferred_shares',
        'updated_by',
    ];

    /** @var list<string> */
    protected $hidden = ['identity'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'identity' => 'encrypted',
            'income_year' => 'integer',
            'employee_id' => 'integer',
            'spouse_owner_id' => 'integer',
            'shares_owned' => 'integer',
            'acquired_on' => 'immutable_date',
            'other_holdings_bp' => 'integer',
            'cost_basis_kronor' => 'integer',
            'saved_space_kronor' => 'integer',
            'own_pay_kronor' => 'integer',
            'related_pay_kronor' => 'integer',
            'company_pay_kronor' => 'integer',
            'subsidiary_pay_kronor' => 'integer',
            'dividend_kronor' => 'integer',
            'transferred_shares' => 'boolean',
            'topped_up_previous_year' => 'boolean',
            'saved_space_carried' => 'boolean',
        ];
    }

    /** The personnummer with the last four digits shown, for the page; the full number never reaches the browser. */
    public function maskedIdentity(): string
    {
        $identity = (string) $this->identity;

        return $identity === '' ? '' : '••••••••-'.substr($identity, -4);
    }
}

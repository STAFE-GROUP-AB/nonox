<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One adjustment of the opening balance sheet at the K2-to-K3 transition
 * (ADR-059 A.2.8, S1-P12; K3 35.8, 35.11): a deferred tax item (35.27,
 * 29.14), a provision K3 requires (chapter 21), or another retroactive
 * restatement with its K3 reference. For PROVISION and OTHER, amount_ore is
 * what is booked on `account`, debit positive (a provision is negative).
 *
 * Frozen once the transition is booked (k3_transition_id set): the model and
 * a database trigger refuse every later change.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property string $kind
 * @property string $description
 * @property ?string $deferred_tax_kind
 * @property ?int $account_from
 * @property ?int $account_to
 * @property ?Money $carrying_ore
 * @property ?Money $tax_value_ore
 * @property Money $recognised_ore
 * @property ?string $recognition_reason
 * @property bool $relief_35_27
 * @property ?string $relief_reason
 * @property ?int $account
 * @property ?Money $amount_ore
 * @property ?string $k3_reference
 * @property ?int $k3_transition_id
 * @property ?int $rate_bp
 * @property int $created_by
 */
class K3TransitionItem extends Model
{
    use BelongsToCompany;

    /** A temporary difference at the transition date: the opening deferred tax (K3 35.27). */
    public const string DEFERRED_TAX = 'DEFERRED_TAX';

    /** A provision K3 chapter 21 requires that K2 did not (for example an informal obligation). */
    public const string PROVISION = 'PROVISION';

    /** Any other retroactive restatement (K3 35.8), with its K3 reference. */
    public const string OTHER = 'OTHER';

    public const array KINDS = [self::DEFERRED_TAX, self::PROVISION, self::OTHER];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'kind',
        'description',
        'deferred_tax_kind',
        'account_from',
        'account_to',
        'carrying_ore',
        'tax_value_ore',
        'recognised_ore',
        'recognition_reason',
        'relief_35_27',
        'relief_reason',
        'account',
        'amount_ore',
        'k3_reference',
        'k3_transition_id',
        'rate_bp',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'carrying_ore' => MoneyCast::class,
            'tax_value_ore' => MoneyCast::class,
            'recognised_ore' => MoneyCast::class,
            'amount_ore' => MoneyCast::class,
            'account_from' => 'integer',
            'account_to' => 'integer',
            'account' => 'integer',
            'rate_bp' => 'integer',
            'relief_35_27' => 'boolean',
        ];
    }

    public function isBooked(): bool
    {
        return $this->k3_transition_id !== null;
    }

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            if ($item->getOriginal('k3_transition_id') !== null) {
                throw new LogicException('A K3 transition item cannot be changed after the transition is booked.');
            }
        });

        static::deleting(function (self $item): void {
            if ($item->getOriginal('k3_transition_id') !== null) {
                throw new LogicException('A K3 transition item cannot be removed after the transition is booked.');
            }
        });
    }
}

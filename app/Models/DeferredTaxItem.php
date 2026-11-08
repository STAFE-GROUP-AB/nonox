<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One temporary difference behind a K3 company's deferred tax for a fiscal
 * year (ADR-059, A.2.6; K3 29.14–29.19). The carrying amount is read from
 * balance sheet accounts (account_from–account_to), from the fixed asset
 * register (fixed_asset_id) or entered (carrying_ore). A tax-loss
 * carry-forward has no carrying amount; its tax value is the loss. For a
 * deferred tax asset, recognised_ore is the deferred tax a person decided to
 * recognise, with the reason (human decision H6); NONOX never proposes it.
 *
 * Frozen once the year's deferred tax is booked (confirmed_by set): the
 * model and a database trigger refuse every later change.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property string $kind
 * @property string $description
 * @property ?int $fixed_asset_id
 * @property ?int $account_from
 * @property ?int $account_to
 * @property ?Money $carrying_ore
 * @property Money $tax_value_ore
 * @property string $tax_value_source
 * @property Money $recognised_ore
 * @property ?string $recognition_reason
 * @property ?int $rate_bp
 * @property ?int $confirmed_by
 * @property int $created_by
 */
class DeferredTaxItem extends Model
{
    use BelongsToCompany;

    /** A building split into components; the carrying amount from the register or the building accounts. */
    public const string BUILDING_COMPONENTS = 'BUILDING_COMPONENTS';

    /** A tax-loss carry-forward (K3 29.19 c): only ever a deferred tax asset. */
    public const string LOSS_CARRY_FORWARD = 'LOSS_CARRY_FORWARD';

    public const string OTHER_ASSET = 'OTHER_ASSET';

    public const string OTHER_LIABILITY = 'OTHER_LIABILITY';

    public const array KINDS = [self::BUILDING_COMPONENTS, self::OTHER_ASSET, self::OTHER_LIABILITY, self::LOSS_CARRY_FORWARD];

    /** The person typed the tax value. */
    public const string SOURCE_ENTERED = 'ENTERED';

    /** The person took the tax value from the income tax return (INK2). */
    public const string SOURCE_INK2 = 'INK2';

    /** The tax value came from the fixed asset register. */
    public const string SOURCE_REGISTER = 'REGISTER';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'kind',
        'description',
        'fixed_asset_id',
        'account_from',
        'account_to',
        'carrying_ore',
        'tax_value_ore',
        'tax_value_source',
        'recognised_ore',
        'recognition_reason',
        'rate_bp',
        'confirmed_by',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'carrying_ore' => MoneyCast::class,
            'tax_value_ore' => MoneyCast::class,
            'recognised_ore' => MoneyCast::class,
            'account_from' => 'integer',
            'account_to' => 'integer',
            'rate_bp' => 'integer',
        ];
    }

    /** Whether the item is an asset (or a loss carry-forward) rather than a liability. */
    public static function isAssetKind(string $kind): bool
    {
        return $kind !== self::OTHER_LIABILITY;
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::BUILDING_COMPONENTS => __('Byggnad'),
            self::LOSS_CARRY_FORWARD => __('Underskottsavdrag'),
            self::OTHER_ASSET => __('Annan tillgång'),
            default => __('Skuld'),
        };
    }

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            if ($item->getOriginal('confirmed_by') !== null) {
                throw new LogicException('A deferred tax item cannot be changed after the deferred tax is booked.');
            }
        });

        static::deleting(function (self $item): void {
            if ($item->getOriginal('confirmed_by') !== null) {
                throw new LogicException('A deferred tax item cannot be removed after the deferred tax is booked.');
            }
        });
    }
}

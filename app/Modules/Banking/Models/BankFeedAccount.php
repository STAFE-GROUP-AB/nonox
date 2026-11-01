<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\Account;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank account in a connection, and the ledger account (for example 1930)
 * its transactions are reconciled against. The provider's account id and
 * the IBAN are encrypted; the last four digits are kept for display.
 *
 * @property int $id
 * @property int $company_id
 * @property int $bank_connection_id
 * @property string $provider_account_id
 * @property string $identification_hash
 * @property ?string $iban
 * @property ?string $iban_last4
 * @property ?string $name
 * @property string $currency
 * @property ?int $account_id
 * @property bool $is_active
 * @property ?CarbonImmutable $fetched_through
 * @property ?CarbonImmutable $last_fetched_at
 */
class BankFeedAccount extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'bank_connection_id',
        'provider_account_id',
        'identification_hash',
        'iban',
        'iban_last4',
        'name',
        'currency',
        'account_id',
        'is_active',
        'fetched_through',
        'last_fetched_at',
    ];

    /** @var list<string> */
    protected $hidden = ['provider_account_id', 'iban'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider_account_id' => 'encrypted',
            'iban' => 'encrypted',
            'is_active' => 'boolean',
            'fetched_through' => 'immutable_date',
            'last_fetched_at' => 'immutable_datetime',
        ];
    }

    public function label(): string
    {
        $name = $this->name ?? __('Bankkonto');

        return $this->iban_last4 === null ? $name : "{$name} ··{$this->iban_last4}";
    }

    /** @return BelongsTo<BankConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /** @return HasMany<BankFeedTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankFeedTransaction::class);
    }

    /** @return HasMany<BankFeedBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(BankFeedBalance::class);
    }
}

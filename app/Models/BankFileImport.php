<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A file of incoming payments from the bank (camt.054), kept as received.
 *
 * @property string $id
 * @property int $company_id
 * @property string $file_name
 * @property string $file_path
 * @property string $file_sha256
 * @property ?string $message_id
 * @property int $credit_count
 * @property Money $credit_total_ore
 * @property int $created_by
 * @property CarbonImmutable $created_at
 */
class BankFileImport extends Model
{
    use BelongsToCompany;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'file_name',
        'file_path',
        'file_sha256',
        'message_id',
        'credit_count',
        'credit_total_ore',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credit_count' => 'integer',
            'credit_total_ore' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        $refuse = function (): never {
            throw new LogicException('Bank file imports cannot be changed or removed.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    /** @return HasMany<BankTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class)->orderBy('booking_date')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per company: the head of the share register's hash chain and its
 * last sequence number, the last board minutes number, and the board's
 * authorisation for an accounting firm to keep the register (ABL 5 kap.
 * 9 §). The row is locked while an entry or a board minute is numbered.
 *
 * @property int $id
 * @property int $company_id
 * @property ?string $head_hash
 * @property int $last_sequence
 * @property int $last_board_minutes_number
 * @property ?CarbonImmutable $firm_authorised_on
 * @property ?string $firm_authorisation_reference
 * @property ?int $firm_authorised_by
 */
class Register extends Model
{
    use BelongsToCompany;

    protected $table = 'boardroom_registers';

    /** @var list<string> */
    protected $fillable = ['company_id', 'head_hash', 'last_sequence', 'last_board_minutes_number', 'firm_authorised_on', 'firm_authorisation_reference', 'firm_authorised_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_sequence' => 'integer',
            'last_board_minutes_number' => 'integer',
            'firm_authorised_on' => 'immutable_date',
        ];
    }

    /** The current company's row, created on first use. */
    public static function current(): self
    {
        // Two first entries at once may both try to create the row: createOrFirst inserts inside a
        // savepoint, so a unique violation does not abort the surrounding PostgreSQL transaction.
        return self::query()->createOrFirst([]);
    }

    /** The current company's row, locked until the transaction ends. */
    public static function locked(): self
    {
        self::current();

        return self::query()->lockForUpdate()->firstOrFail();
    }
}

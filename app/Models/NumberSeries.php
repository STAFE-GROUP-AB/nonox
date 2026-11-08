<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A running number per company, such as customer numbers and invoice
 * numbers (AGENTS.md §3.4). take() must run inside a transaction: the row is
 * locked until it commits, so two callers never get the same number.
 *
 * @property string $key
 * @property int $next_number
 */
class NumberSeries extends Model
{
    use BelongsToCompany;

    public const string CUSTOMERS = 'customers';

    public const string INVOICES = 'invoices';

    public const string QUOTES = 'quotes';

    public const string ORDERS = 'orders';

    public const string SUPPLIERS = 'suppliers';

    public const string SUPPLIER_INVOICES = 'supplier_invoices';

    public const string PAYMENT_BATCHES = 'payment_batches';

    public const string AGREEMENTS = 'agreements';

    public const string EMPLOYEES = 'employees';

    public const string WORK_ORDERS = 'work_orders';

    protected $table = 'number_series';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'key',
        'next_number',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }

    public static function take(string $key): int
    {
        $series = self::query()->where('key', $key)->lockForUpdate()->first()
            ?? self::create(['key' => $key, 'next_number' => 1]);

        $number = $series->next_number;
        $series->update(['next_number' => $number + 1]);

        return $number;
    }

    public static function peek(string $key): int
    {
        return (int) (self::query()->where('key', $key)->value('next_number') ?? 1);
    }
}

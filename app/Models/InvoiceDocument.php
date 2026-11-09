<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The PDF of an issued invoice or credit invoice exactly as it was issued
 * (ADR-075, decision O6), on the private (encrypted) disk with its SHA-256.
 * Written once and never changed (PostgreSQL trigger and here).
 *
 * @property int $id
 * @property int $company_id
 * @property string $invoice_id
 * @property string $path
 * @property string $sha256
 * @property int $size
 * @property CarbonImmutable $created_at
 */
class InvoiceDocument extends Model
{
    use BelongsToCompany;

    public const string DISK = 'local';

    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['company_id', 'invoice_id', 'path', 'sha256', 'size'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A stored issued invoice PDF never changes (ADR-075).'));
    }
}

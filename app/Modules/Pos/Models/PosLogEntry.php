<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What happened on a till connection, in Swedish, for the log under Kassa.
 * Never holds tokens or the till's answers in full.
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_connection_id
 * @property string $level
 * @property string $message
 * @property array<string, mixed> $context
 * @property CarbonImmutable $created_at
 */
class PosLogEntry extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['company_id', 'pos_connection_id', 'level', 'message', 'context', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}

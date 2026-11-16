<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The NONOX Connect add-on switch for one connection of one company
 * (ADR-064 §2, ADR-080 addendum step 2). Ids and enums only.
 *
 * @property int $id
 * @property int $company_id
 * @property string $connection
 * @property bool $enabled
 * @property ?int $changed_by
 * @property ?CarbonImmutable $changed_at
 */
class ConnectAddon extends Model
{
    use BelongsToCompany;

    protected $table = 'connect_addons';

    /** @var list<string> */
    protected $fillable = ['company_id', 'connection', 'enabled', 'changed_by', 'changed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'changed_by' => 'integer',
            'changed_at' => 'immutable_datetime',
        ];
    }
}

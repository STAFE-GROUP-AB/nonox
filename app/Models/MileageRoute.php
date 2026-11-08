<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A route a person drives often, such as "Kontoret – Lagret" and 14,2 km,
 * kept as text and a number so a trip is quicker to log. No map service and
 * no position is used (ADR-072 §2).
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $label encrypted
 * @property int $distance_hm
 */
class MileageRoute extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'user_id',
        'label',
        'distance_hm',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'label' => 'encrypted',
            'distance_hm' => 'integer',
        ];
    }
}

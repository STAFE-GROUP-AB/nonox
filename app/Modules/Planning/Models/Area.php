<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Modules\StaffLedger\Models\Location;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An area of the business that shifts are planned in (avdelning: Kök,
 * Servering, Bar), with the colour its shifts are shown in. Archived, never
 * deleted, while shifts refer to it.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $colour
 * @property ?int $location_id
 * @property int $sort
 * @property ?CarbonImmutable $archived_at
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class Area extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_areas';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'colour', 'location_id', 'sort', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'sort' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}

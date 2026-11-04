<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved filter over the CRM's people (ADR-048): who a mailing goes to.
 * The filters are read by SegmentQuery; the people are found when a mailing
 * is counted or sent, never stored on the segment.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class Segment extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_segments';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'filters'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

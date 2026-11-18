<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Analysis\Reports\ReportVisibility;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved report of the analysis view (ADR-081 gap 7, E18): the choices of an
 * analysis as keys, never its results. It is run again for whoever opens it.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $owner_user_id
 * @property string $name
 * @property string $dataset
 * @property array<string, mixed> $definition
 * @property ReportVisibility $visibility
 * @property bool $pinned
 * @property CarbonImmutable $updated_at
 */
class ReportDefinition extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'owner_user_id', 'name', 'dataset', 'definition', 'visibility', 'pinned'];

    /** @var array<string, mixed> */
    protected $attributes = ['visibility' => 'PRIVATE', 'pinned' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'visibility' => ReportVisibility::class,
            'pinned' => 'boolean',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> null when the owner has left: only a company report stays then */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}

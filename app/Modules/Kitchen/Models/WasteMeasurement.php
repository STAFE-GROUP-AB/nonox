<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Plate waste weighed on a day (ADR-073 §6): food already sold, so no
 * stock movement; its ingredients left stock with the sale. Append-only.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $measured_on
 * @property int $kg_milli
 * @property ?string $note
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 */
class WasteMeasurement extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    protected $table = 'kitchen_waste_measurements';

    /** @var list<string> */
    protected $fillable = ['company_id', 'measured_on', 'kg_milli', 'note', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'measured_on' => 'immutable_date',
            'kg_milli' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Plate-waste measurements cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Plate-waste measurements cannot be deleted.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

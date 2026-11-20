<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\PriceRounding;
use App\Domain\Pricing\PriceUpdateKind;
use App\Domain\Pricing\PriceUpdateStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A mass price update, "Prisuppdatering" (ADR-082 §8): what was changed (ids
 * only), by which rule and rounding, from which date, and what came of it.
 * The flow (preview, apply, schedule, revert) is built in P6.
 *
 * @property int $id
 * @property int $company_id
 * @property PriceUpdateKind $kind
 * @property array<string, mixed> $scope
 * @property array<string, mixed> $rule
 * @property PriceRounding $rounding_rule
 * @property CarbonImmutable $effective_date
 * @property PriceUpdateStatus $status
 * @property ?array<string, int> $counts
 * @property ?string $file_path
 * @property ?string $file_sha256
 * @property int $created_by
 * @property ?CarbonImmutable $applied_at
 */
class PriceUpdateRun extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'kind', 'scope', 'rule', 'rounding_rule', 'effective_date', 'status', 'counts',
        'file_path', 'file_sha256', 'created_by', 'applied_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['rounding_rule' => 'NONE', 'status' => 'PREVIEW'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => PriceUpdateKind::class,
            'scope' => 'array',
            'rule' => 'array',
            'rounding_rule' => PriceRounding::class,
            'effective_date' => 'immutable_date',
            'status' => PriceUpdateStatus::class,
            'counts' => 'array',
            'applied_at' => 'immutable_datetime',
        ];
    }
}

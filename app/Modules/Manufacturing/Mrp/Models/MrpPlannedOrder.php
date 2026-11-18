<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp\Models;

use App\Models\Article;
use App\Models\Supplier;
use App\Modules\Manufacturing\Mrp\PlannedOrderException;
use App\Modules\Manufacturing\Mrp\PlannedOrderKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned order of a run (ADR-081 gap 5): what to buy or make, how much
 * (milli-units of the stock unit), when to release it and when it is
 * needed, the demand it covers and the advice on it. It is a proposal: a
 * person turns it into a draft purchase or production order, and the draft
 * is recorded here.
 *
 * @property int $id
 * @property int $company_id
 * @property int $mrp_run_id
 * @property int $article_id
 * @property PlannedOrderKind $kind
 * @property int $quantity_milli
 * @property CarbonImmutable $release_on
 * @property CarbonImmutable $due_on
 * @property ?int $supplier_id
 * @property list<array{kind: string, quantity_milli: int, on: ?string, id: string|int|null, number: ?int, article_id: ?int}> $pegging
 * @property ?PlannedOrderException $exception
 * @property ?string $converted_type
 * @property ?int $converted_id
 * @property ?CarbonImmutable $converted_at
 * @property ?int $converted_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class MrpPlannedOrder extends Model
{
    use BelongsToCompany;

    public const string PURCHASE_ORDER = 'purchase_order';

    public const string PRODUCTION_ORDER = 'production_order';

    /** @var list<string> */
    protected $fillable = ['company_id', 'mrp_run_id', 'article_id', 'kind', 'quantity_milli', 'release_on', 'due_on', 'supplier_id', 'pegging', 'exception', 'converted_type', 'converted_id', 'converted_at', 'converted_by'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'pegging' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => PlannedOrderKind::class,
            'quantity_milli' => 'integer',
            'release_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'pegging' => 'array',
            'exception' => PlannedOrderException::class,
            'converted_id' => 'integer',
            'converted_at' => 'immutable_datetime',
        ];
    }

    public function isConverted(): bool
    {
        return $this->converted_type !== null;
    }

    /** @return BelongsTo<MrpRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(MrpRun::class, 'mrp_run_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stock movement to send to a till as a change (a delta, never an
 * absolute balance), at most once per connection and movement (ADR-042).
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_connection_id
 * @property int $stock_movement_id
 * @property int $article_id
 * @property int $delta_milli
 * @property string $status
 * @property int $attempts
 * @property string $external_uuid
 * @property ?string $last_error
 * @property ?CarbonImmutable $sent_at
 */
class PosStockPush extends Model
{
    use BelongsToCompany;

    public const string PENDING = 'PENDING';

    public const string SENT = 'SENT';

    public const string FAILED = 'FAILED';

    public const string SKIPPED = 'SKIPPED';

    /** @var list<string> */
    protected $fillable = ['company_id', 'pos_connection_id', 'stock_movement_id', 'article_id', 'delta_milli', 'status', 'attempts', 'external_uuid', 'last_error', 'sent_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}

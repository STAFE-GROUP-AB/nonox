<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\SalesDocument;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery date confirmed to a customer (ADR-071 §10, §13.5): the old and
 * the new date, who decided, and whether and when the customer was told.
 *
 * @property int $id
 * @property int $company_id
 * @property string $sales_document_id
 * @property ?int $deviation_id
 * @property ?CarbonImmutable $old_on
 * @property CarbonImmutable $new_on
 * @property ?int $decided_by
 * @property ?string $message_to
 * @property ?CarbonImmutable $message_sent_at
 * @property ?int $message_sent_by
 * @property ?CarbonImmutable $created_at
 */
class CustomerDateChange extends Model
{
    use BelongsToCompany;

    protected $table = 'production_customer_date_changes';

    /** @var list<string> */
    protected $fillable = ['company_id', 'sales_document_id', 'deviation_id', 'old_on', 'new_on', 'decided_by', 'message_to', 'message_sent_at', 'message_sent_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_on' => 'immutable_date',
            'new_on' => 'immutable_date',
            'message_sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }
}

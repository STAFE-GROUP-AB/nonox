<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A verified webhook message from a till, stored before it is handled so
 * the answer goes back at once; a message id is stored once (ADR-042).
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_connection_id
 * @property string $message_id
 * @property string $event_name
 * @property string $body
 * @property ?CarbonImmutable $processed_at
 * @property ?string $last_error
 */
class PosWebhookMessage extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'pos_connection_id', 'message_id', 'event_name', 'body', 'processed_at', 'last_error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }
}

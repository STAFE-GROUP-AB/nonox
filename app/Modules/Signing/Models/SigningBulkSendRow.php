<?php

declare(strict_types=1);

namespace App\Modules\Signing\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a bulk send (ADR-046 S15): one envelope to make. The people of
 * the row (addresses, any access code, an optional name) are kept in
 * `payload`, encrypted, only until the row is sent or the run ends; then the
 * payload is emptied and the row keeps its number, its status, a short
 * reason (never an address) and the envelope it became.
 *
 * @property int $id
 * @property int $company_id
 * @property string $bulk_send_id
 * @property int $row_number
 * @property string $status INVALID, PENDING, PROCESSING, SENT, FAILED or SKIPPED
 * @property ?string $reason
 * @property ?array{signers: list<array{role_key: string, email: string, name: string, language: string, access_code: string}>} $payload
 * @property ?string $document_id
 * @property ?string $envelope_id
 * @property ?CarbonImmutable $processed_at
 */
class SigningBulkSendRow extends Model
{
    use BelongsToCompany;

    public const null CREATED_AT = null;

    public const null UPDATED_AT = null;

    public const string INVALID = 'INVALID';

    public const string PENDING = 'PENDING';

    public const string PROCESSING = 'PROCESSING';

    public const string SENT = 'SENT';

    public const string FAILED = 'FAILED';

    public const string SKIPPED = 'SKIPPED';

    /** Times are written with their offset, like the rest of signing. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = ['company_id', 'bulk_send_id', 'row_number', 'status', 'reason', 'payload', 'document_id', 'envelope_id', 'processed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'payload' => 'encrypted:array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SigningBulkSend, $this> */
    public function bulkSend(): BelongsTo
    {
        return $this->belongsTo(SigningBulkSend::class, 'bulk_send_id');
    }
}

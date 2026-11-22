<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\PriceFloorApprovalStatus;
use App\Domain\Pricing\PriceFloorDocument;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The approval of one version of an invoice, quote or order whose manual
 * prices are under the company's lowest contribution margin (ADR-082 §9,
 * P7). The version is the fingerprint of the document's rows: a changed row
 * is a new version that needs its own approval, so nobody approves rows they
 * have not seen. Whoever wrote the document, or asked for the approval, may
 * not decide it while the company requires separate approval.
 *
 * @property int $id
 * @property int $company_id
 * @property PriceFloorDocument $document_type
 * @property string $document_id
 * @property string $fingerprint
 * @property PriceFloorApprovalStatus $status
 * @property int $requested_by
 * @property ?int $decided_by
 * @property ?CarbonImmutable $decided_at
 * @property ?string $reason
 * @property ?CarbonImmutable $created_at
 */
class PriceFloorApproval extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'document_type', 'document_id', 'fingerprint', 'status', 'requested_by', 'decided_by', 'decided_at', 'reason'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'PENDING'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_type' => PriceFloorDocument::class,
            'status' => PriceFloorApprovalStatus::class,
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

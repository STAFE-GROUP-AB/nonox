<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Group\IntercompanyDeliveryStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * That the company tried to deliver one of its invoices to another company
 * of the same group (ADR-081 E27), and whether it was delivered. The row is
 * the sender's: it holds no id of the receiver's inbox message or supplier
 * invoice, and a refusal keeps no reason, so the sender learns nothing about
 * the receiver beyond "levererad". Rows are only ever added.
 *
 * @property int $id
 * @property int $company_id
 * @property string $invoice_id
 * @property string $recipient_org_number formatted, NNNNNN-NNNN
 * @property ?int $corporate_group_id
 * @property IntercompanyDeliveryStatus $status
 * @property ?CarbonImmutable $delivered_at
 * @property ?int $delivered_by
 * @property ?string $xml_path the sender's copy of the delivered e-invoice, on the private disk
 * @property ?string $xml_sha256
 * @property ?CarbonImmutable $created_at
 * @property-read ?Invoice $invoice
 * @property-read ?User $deliverer
 */
class IntercompanyDelivery extends Model
{
    public const string DISK = 'local';

    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'invoice_id', 'recipient_org_number', 'corporate_group_id', 'status', 'delivered_at', 'delivered_by', 'xml_path', 'xml_sha256'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('An intercompany delivery is never changed.'));
        static::deleting(fn (): never => throw new LogicException('An intercompany delivery is never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => IntercompanyDeliveryStatus::class,
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }
}

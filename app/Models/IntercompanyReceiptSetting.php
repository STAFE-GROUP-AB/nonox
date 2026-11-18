<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A group company from which this company accepts intra-group invoices into
 * its inbox (ADR-081 E27). The sender is named by organisation number, never
 * by another tenant's id. Nothing is delivered without a row here that is
 * switched on, written by the company's owner or administrator.
 *
 * @property int $id
 * @property int $company_id
 * @property string $sender_org_number formatted, NNNNNN-NNNN
 * @property bool $enabled
 * @property ?int $enabled_by
 */
class IntercompanyReceiptSetting extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'sender_org_number', 'enabled', 'enabled_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}

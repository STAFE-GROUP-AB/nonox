<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A photo taken on site (ADR-074 §8, §9), on the private disk. It may show
 * a home's interior or people (AO-R39); it is räkenskapsinformation only
 * when attached to an ÄTA or an invoice basis.
 *
 * @property int $id
 * @property int $company_id
 * @property int $work_order_id
 * @property ?int $change_order_id
 * @property string $path
 * @property ?string $caption
 * @property ?int $uploaded_by
 */
class WorkOrderPhoto extends Model
{
    use BelongsToCompany;

    public const string DISK = 'local';

    /** @var list<string> */
    protected $fillable = ['company_id', 'work_order_id', 'change_order_id', 'path', 'caption', 'uploaded_by'];
}

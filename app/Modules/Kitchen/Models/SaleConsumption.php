<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * That a till row has been drawn from stock through its recipe, once
 * (unique on pos_sale_row_id; ADR-073 §5).
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_sale_row_id
 * @property int $kitchen_consumption_run_id
 */
class SaleConsumption extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    protected $table = 'kitchen_sale_consumptions';

    /** @var list<string> */
    protected $fillable = ['company_id', 'pos_sale_row_id', 'kitchen_consumption_run_id'];
}

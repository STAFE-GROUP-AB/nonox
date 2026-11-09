<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * What till returns of a dish left over at a location, per ingredient, to
 * net against later sales (review P2.5). Returns never put stock back.
 *
 * @property int $id
 * @property int $company_id
 * @property int $location_id
 * @property int $article_id
 * @property int $quantity_milli
 */
class ConsumptionCredit extends Model
{
    use BelongsToCompany;

    protected $table = 'kitchen_consumption_credits';

    /** @var list<string> */
    protected $fillable = ['company_id', 'location_id', 'article_id', 'quantity_milli'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity_milli' => 'integer'];
    }
}

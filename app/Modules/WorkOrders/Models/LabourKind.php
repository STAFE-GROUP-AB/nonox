<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Modules\WorkOrders\Domain\LabourKindType;
use App\Modules\WorkOrders\Domain\LabourUnit;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of work in the company's list (ADR-074 §3, O7): Arbete, Resa and
 * the company's own. Only kinds of type LABOUR become ROT or RUT labour.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property LabourKindType $type
 * @property LabourUnit $unit
 * @property Money $default_rate_ore per hour, trip or km, excluding VAT
 * @property bool $is_active
 * @property int $position
 */
class LabourKind extends Model
{
    use BelongsToCompany;

    protected $table = 'work_order_labour_kinds';

    /** @var list<string> */
    protected $fillable = ['company_id', 'code', 'name', 'type', 'unit', 'default_rate_ore', 'is_active', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LabourKindType::class,
            'unit' => LabourUnit::class,
            'default_rate_ore' => MoneyCast::class,
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function isLabour(): bool
    {
        return $this->type === LabourKindType::Labour;
    }
}

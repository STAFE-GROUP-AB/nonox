<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable pension plan (ITP1-like, SAF-LO-like or custom): the premium
 * as a share of pay up to 7.5 income base amounts a year, and above it.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $template
 * @property array{below_bp?: int, above_bp?: int} $rules
 */
class PensionPlan extends Model
{
    use BelongsToCompany;

    protected $table = 'pension_plans';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'template',
        'rules',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rules' => 'array',
        ];
    }
}

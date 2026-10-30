<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable pension plan (ITP1-like, ITP2-like, SAF-LO-like or custom), percentages as data.
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

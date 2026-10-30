<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable collective agreement template (PAYROLL_MODULE.md B.8). Rules only, never agreement text.
 */
class CollectiveAgreement extends Model
{
    use BelongsToCompany;

    protected $table = 'collective_agreements';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
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

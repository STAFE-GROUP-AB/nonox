<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A system that sends payroll files (löneunderlag), such as a scheduling
 * system, with the company's mapping of its pay codes to NONOX's.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 */
class PayrollImportSource extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_import_sources';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name'];

    /** @return HasMany<PayrollImportMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(PayrollImportMapping::class);
    }
}

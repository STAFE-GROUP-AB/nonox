<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One external pay code (löneart or tidkod) of a source system and the
 * company's pay code it becomes, or that its lines are left out. A code
 * seen in a file but not yet mapped has neither.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payroll_import_source_id
 * @property string $external_code
 * @property ?string $description
 * @property ?int $pay_code_id
 * @property bool $ignored
 */
class PayrollImportMapping extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_import_mappings';

    /** @var list<string> */
    protected $fillable = ['company_id', 'payroll_import_source_id', 'external_code', 'description', 'pay_code_id', 'ignored'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ignored' => 'boolean'];
    }

    /** @return BelongsTo<PayCode, $this> */
    public function payCode(): BelongsTo
    {
        return $this->belongsTo(PayCode::class);
    }

    public function isMapped(): bool
    {
        return $this->ignored || $this->pay_code_id !== null;
    }
}

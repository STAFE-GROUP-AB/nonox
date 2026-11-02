<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operation sent from the scanning app (ADR-040), kept by the UUID the
 * phone made for it, so a resend returns the first answer instead of
 * posting again.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $uuid
 * @property string $kind
 * @property string $status
 * @property ?array<string, mixed> $result
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class ScanOperation extends Model
{
    use BelongsToCompany;

    public const string PENDING = 'PENDING';

    public const string OK = 'OK';

    public const string REJECTED = 'REJECTED';

    public const string NOT_FOUND = 'NOT_FOUND';

    protected $table = 'inventory_scan_operations';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'user_id',
        'uuid',
        'kind',
        'status',
        'result',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'result' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

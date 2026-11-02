<?php

declare(strict_types=1);

namespace App\Modules\Connect\Models;

use App\Models\SieImport;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file the SFTP server reported as uploaded (ADR-032, "SFTP"): received,
 * then either checked into a SIE import that waits for a person to confirm
 * it, or rejected with a reason. The file itself never stays in the drop.
 *
 * @property string $id
 * @property int $company_id
 * @property int $account_id
 * @property string $event_key
 * @property string $file_name
 * @property int $size
 * @property ?string $ip
 * @property string $status
 * @property ?string $reason
 * @property ?string $sha256
 * @property ?int $sie_import_id
 * @property ?CarbonImmutable $processed_at
 * @property CarbonImmutable $created_at
 * @property-read ?SieImport $sieImport
 */
class ConnectSftpUpload extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const string RECEIVED = 'received';

    public const string PROCESSING = 'processing';

    /** Checked; a SIE import waits for a person to read the report and confirm. */
    public const string ANALYSED = 'analysed';

    public const string REJECTED = 'rejected';

    protected $table = 'connect_sftp_uploads';

    /** @var list<string> */
    protected $fillable = ['company_id', 'account_id', 'event_key', 'file_name', 'size', 'ip', 'status', 'reason', 'sha256', 'sie_import_id', 'processed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SieImport, $this> */
    public function sieImport(): BelongsTo
    {
        return $this->belongsTo(SieImport::class);
    }
}

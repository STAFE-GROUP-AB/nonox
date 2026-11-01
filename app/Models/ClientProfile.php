<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\WritableWhenArchived;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accounting firm's view of a client company: the responsible
 * consultant, seen by everyone in the company, and the firm's note, seen
 * only by members with the Redovisningskonsult role.
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $responsible_user_id
 * @property ?string $note
 * @property ?int $note_updated_by
 * @property ?CarbonImmutable $note_updated_at
 */
class ClientProfile extends Model implements WritableWhenArchived
{
    use BelongsToCompany;

    /** The role that is the accounting firm in a client company. */
    public const string FIRM_ROLE = 'accountant';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'responsible_user_id',
        'note',
        'note_updated_by',
        'note_updated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'note_updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}

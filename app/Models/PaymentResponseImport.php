<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A file the bank sent in answer to payment files (pain.002, camt.054 or
 * camt.053), kept as received with its SHA-256. A file is read once.
 *
 * @property string $id
 * @property int $company_id
 * @property string $format
 * @property string $file_name
 * @property string $file_path
 * @property string $file_sha256
 * @property ?string $message_id
 * @property int $created_by
 * @property CarbonImmutable $created_at
 */
class PaymentResponseImport extends Model
{
    use BelongsToCompany;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'format',
        'file_name',
        'file_path',
        'file_sha256',
        'message_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        $refuse = function (): never {
            throw new LogicException('Payment response files cannot be changed or removed.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    /** "Statusrapport (pain.002)" */
    public function formatLabel(): string
    {
        return match ($this->format) {
            'pain.002' => __('Statusrapport (pain.002)'),
            'camt.053' => __('Kontoutdrag (camt.053)'),
            default => __('Debiteringsavisering (camt.054)'),
        };
    }

    /** @return HasMany<PaymentResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(PaymentResponse::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

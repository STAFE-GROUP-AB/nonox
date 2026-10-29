<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\ImmutableVoucher;
use App\Domain\Accounting\VoucherStatus;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A supporting document (underlag) for a voucher, AGENTS.md §6.4. Never
 * changed; removable only while the voucher is a draft. A database trigger
 * backs this up.
 *
 * @property string $voucher_id
 * @property string $file_path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property ?int $uploaded_by
 */
class VoucherAttachment extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'voucher_id',
        'file_path',
        'original_name',
        'mime',
        'size',
        'sha256',
        'uploaded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Voucher attachments cannot be changed.');
        });

        static::deleting(function (VoucherAttachment $attachment): void {
            if (Voucher::query()->whereKey($attachment->voucher_id)->where('status', VoucherStatus::Posted->value)->exists()) {
                throw ImmutableVoucher::cannotChangeRows($attachment->voucher_id);
            }
        });
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function humanSize(): string
    {
        return $this->size >= 1_048_576
            ? number_format($this->size / 1_048_576, 1, ',', ' ').' MB'
            : max(1, (int) round($this->size / 1024)).' kB';
    }
}

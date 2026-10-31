<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attachment of a received message, stored unaltered. A blocked
 * attachment (dangerous type, virus) is kept as evidence but never served.
 *
 * @property int $id
 * @property int $company_id
 * @property string $inbox_message_id
 * @property int $position
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $path
 * @property ?string $blocked_reason
 */
class InboxAttachment extends Model
{
    use BelongsToCompany;

    /** Shown inline in the preview; everything else is a download. */
    public const array PREVIEWABLE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** @var list<string> */
    protected $fillable = ['company_id', 'inbox_message_id', 'position', 'filename', 'mime', 'size', 'sha256', 'path', 'blocked_reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'size' => 'integer'];
    }

    /** @return BelongsTo<InboxMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(InboxMessage::class, 'inbox_message_id');
    }

    public function isBlocked(): bool
    {
        return $this->blocked_reason !== null;
    }

    public function isPreviewable(): bool
    {
        return ! $this->isBlocked() && in_array($this->mime, self::PREVIEWABLE, true);
    }

    public function extension(): string
    {
        return mb_strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
    }

    public function humanSize(): string
    {
        return $this->size >= 1_048_576
            ? number_format($this->size / 1_048_576, 1, ',', ' ').' MB'
            : max(1, (int) round($this->size / 1024)).' kB';
    }
}

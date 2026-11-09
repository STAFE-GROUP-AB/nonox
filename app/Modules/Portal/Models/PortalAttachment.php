<?php

declare(strict_types=1);

namespace App\Modules\Portal\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a portal message (ADR-075 §6): PDF, JPEG or PNG, at
 * most 10 MB, checked by content like the inbox's attachments, kept on
 * the private (encrypted) disk and streamed, never a public URL.
 *
 * @property int $id
 * @property int $company_id
 * @property int $message_id
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property CarbonImmutable $created_at
 */
class PortalAttachment extends Model
{
    use BelongsToCompany;

    public const string DISK = 'local';

    public const int MAX_BYTES = 10 * 1024 * 1024;

    public const int MAX_PER_MESSAGE = 5;

    /** Allowed types, by content. */
    public const array MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public const null UPDATED_AT = null;

    protected $table = 'customer_portal_attachments';

    /** @var list<string> */
    protected $fillable = ['company_id', 'message_id', 'path', 'original_name', 'mime', 'size', 'sha256'];

    /** @var list<string> */
    protected $hidden = ['path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<PortalMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(PortalMessage::class, 'message_id');
    }
}

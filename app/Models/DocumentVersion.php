<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One version of a company document (ADR-081 gap 9). Append-only: a new
 * version is a new row and the old ones stay. The file lives encrypted on
 * the private disk (EncryptedAdapter) with its SHA-256; `path` is in the
 * StoredFiles registry. extracted_text is the PDF's text layer for the
 * search, derived from the file and removed with it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $document_id
 * @property int $version
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $original_name
 * @property ?string $extracted_text
 * @property ?int $uploaded_by
 * @property CarbonImmutable $created_at
 */
class DocumentVersion extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'document_id',
        'version',
        'path',
        'mime',
        'size',
        'sha256',
        'original_name',
        'extracted_text',
        'uploaded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Document versions cannot be changed.');
        });
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function humanSize(): string
    {
        return $this->size >= 1_048_576
            ? number_format($this->size / 1_048_576, 1, ',', ' ').' MB'
            : max(1, (int) round($this->size / 1024)).' kB';
    }
}

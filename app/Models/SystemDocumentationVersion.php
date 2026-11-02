<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A version of the systemdokumentation as this installation took it into
 * use (#44). Instance-wide, not company data: the same document describes
 * the system for every company. Append-only; a database trigger backs it up.
 *
 * @property int $id
 * @property ?string $version
 * @property ?CarbonImmutable $document_date
 * @property string $sha256
 * @property string $content
 * @property ?string $program_version
 * @property ?string $program_sha
 * @property CarbonImmutable $recorded_at
 */
class SystemDocumentationVersion extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['version', 'document_date', 'sha256', 'content', 'program_version', 'program_sha', 'recorded_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_date' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A recorded version of the systemdokumentation cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A recorded version of the systemdokumentation cannot be removed.'));
    }
}

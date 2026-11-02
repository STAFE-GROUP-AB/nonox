<?php

declare(strict_types=1);

namespace App\Support\Build;

use App\Models\SystemDocumentationVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Records what this installation took into use, once per change: the
 * version of the systemdokumentation (#44). Runs after `migrate` (also when
 * nothing was pending), from `nonox:build-info` at deploy, from
 * `nonox:record-version` and every hour from the scheduler, so a
 * self-hosted installation records a new version without a deploy script.
 */
final class VersionRecorder
{
    /** @return array{documentation: ?SystemDocumentationVersion} */
    public static function record(): array
    {
        return [
            'documentation' => SystemDocumentation::record(),
        ];
    }

    /**
     * The same, but never fails the deploy or the migration that runs it:
     * before the table exists, or on any error, it logs and returns.
     *
     * @return array{documentation: ?SystemDocumentationVersion}|null
     */
    public static function recordQuietly(): ?array
    {
        try {
            if (! Schema::hasTable('system_documentation_versions')) {
                return null;
            }

            return self::record();
        } catch (Throwable $exception) {
            Log::warning('The program version could not be recorded.', ['exception' => $exception->getMessage()]);

            return null;
        }
    }
}

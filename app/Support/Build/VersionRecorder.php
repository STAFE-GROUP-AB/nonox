<?php

declare(strict_types=1);

namespace App\Support\Build;

use App\Models\AuditLog;
use App\Models\SystemDocumentationVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Records what this installation took into use, once per change: the
 * program version as an audit entry `system.version_deployed` (BFNAR 2013:2
 * p. 9.16 andra stycket: the behandlingshistorik describes changes to the
 * system and when they were made, e.g. a new program version; #43), and
 * the version of the systemdokumentation (#44). Runs after `migrate` (also when
 * nothing was pending), from `nonox:build-info` at deploy, from
 * `nonox:record-version` and every hour from the scheduler, so a
 * self-hosted installation records a new version without a deploy script.
 */
final class VersionRecorder
{
    public const string ACTION = 'system.version_deployed';

    /** @return array{program: ?AuditLog, documentation: ?SystemDocumentationVersion} */
    public static function record(): array
    {
        return [
            'program' => self::recordProgram(),
            'documentation' => SystemDocumentation::record(),
        ];
    }

    /**
     * One entry each time the running version or commit differs from the
     * last one recorded. The entry belongs to no company; the archive
     * package of every company carries it.
     */
    public static function recordProgram(): ?AuditLog
    {
        $version = BuildInfo::version();
        $sha = BuildInfo::fullSha();
        $last = AuditLog::query()->whereNull('company_id')->where('action', self::ACTION)->latest('id')->first();

        if ($last !== null && ($last->payload['version'] ?? null) === $version && ($last->payload['sha'] ?? null) === $sha) {
            return null;
        }

        return AuditLog::create([
            'company_id' => null,
            'user_id' => null,
            'action' => self::ACTION,
            'payload' => [
                'version' => $version,
                'build' => BuildInfo::build(),
                'sha' => $sha,
                'built_at' => BuildInfo::builtAt(),
                'documentation_version' => SystemDocumentation::version(),
                'previous_version' => $last?->payload['version'] ?? null,
            ],
        ]);
    }

    /**
     * The same, but never fails the deploy or the migration that runs it:
     * before the table exists, or on any error, it logs and returns.
     *
     * @return array{program: ?AuditLog, documentation: ?SystemDocumentationVersion}|null
     */
    public static function recordQuietly(): ?array
    {
        try {
            if (! Schema::hasTable('system_documentation_versions') || ! Schema::hasTable('audit_logs')) {
                return null;
            }

            return self::record();
        } catch (Throwable $exception) {
            Log::warning('The program version could not be recorded.', ['exception' => $exception->getMessage()]);

            return null;
        }
    }
}

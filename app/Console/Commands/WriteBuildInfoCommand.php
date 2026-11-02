<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Build\BuildInfo;
use App\Support\Build\VersionRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Writes the build number of this release, run by the deploy script. The
 * build number is the number of commits up to this one on GitHub, so every
 * push raises it without anybody editing a file. Deploys clone shallowly,
 * so the count comes from the GitHub API, with local git as fallback.
 */
class WriteBuildInfoCommand extends Command
{
    protected $signature = 'nonox:build-info {--sha= : The commit being deployed (default: git HEAD)}';

    protected $description = 'Skriver version och byggnummer för den här releasen.';

    public function handle(): int
    {
        $sha = $this->option('sha') ?: trim(Process::path(base_path())->run('git rev-parse HEAD')->output());

        if (! preg_match('/^[0-9a-f]{40}$/', (string) $sha)) {
            $this->error('Hittar ingen commit. Ange --sha.');

            return self::FAILURE;
        }

        $build = $this->countOnGitHub($sha) ?? $this->countLocally();

        file_put_contents(base_path(BuildInfo::FILE), json_encode([
            'build' => $build,
            'sha' => $sha,
            'built_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT)."\n");

        BuildInfo::fake(null);
        $this->info('Byggd: '.BuildInfo::line());
        VersionRecorder::recordQuietly();

        return self::SUCCESS;
    }

    /** One commit per page: the page number of the last page is the count. */
    private function countOnGitHub(string $sha): ?int
    {
        $repository = (string) config('app.landing.github_repository');

        try {
            $response = Http::timeout(10)->acceptJson()->get("https://api.github.com/repos/{$repository}/commits", ['sha' => $sha, 'per_page' => 1]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        if (preg_match('/[?&]page=(\d+)>; rel="last"/', (string) $response->header('Link'), $match) === 1) {
            return (int) $match[1];
        }

        return count((array) $response->json()) === 1 ? 1 : null;
    }

    private function countLocally(): ?int
    {
        $result = Process::path(base_path())->run('git rev-list --count HEAD');
        $count = (int) trim($result->output());

        // A shallow clone counts only what it fetched.
        $shallow = trim(Process::path(base_path())->run('git rev-parse --is-shallow-repository')->output()) === 'true';

        return $result->successful() && $count > 0 && ! $shallow ? $count : null;
    }
}

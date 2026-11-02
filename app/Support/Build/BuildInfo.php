<?php

declare(strict_types=1);

namespace App\Support\Build;

/**
 * Which version and build is running, for support and the public pages.
 *
 * The version is config('app.version') (major.minor) followed by the build
 * number: the number of commits on main, so it goes up by itself on every
 * push. `nonox:build-info` writes it to bootstrap/cache/build.json at deploy;
 * without that file (local development) the build is unknown.
 */
final class BuildInfo
{
    public const string FILE = 'bootstrap/cache/build.json';

    /** @var array{build: ?int, sha: ?string, built_at: ?string}|null */
    private static ?array $cache = null;

    /** e.g. "0.9.117", or "0.9" without a build number. */
    public static function version(): string
    {
        $base = (string) config('app.version');
        $build = self::read()['build'];

        return $build === null ? $base : "{$base}.{$build}";
    }

    public static function build(): ?int
    {
        return self::read()['build'];
    }

    public static function sha(): ?string
    {
        $sha = self::read()['sha'];

        return $sha === null ? null : substr($sha, 0, 7);
    }

    /** The whole commit hash, for the audit trail. */
    public static function fullSha(): ?string
    {
        return self::read()['sha'];
    }

    public static function builtAt(): ?string
    {
        return self::read()['built_at'];
    }

    public static function stage(): string
    {
        return (string) config('app.stage');
    }

    /** One line for footers and support: "Version 0.9.117 · Öppen beta · a844fdf". */
    public static function line(): string
    {
        return collect([__('Version :version', ['version' => self::version()]), self::stage(), self::sha()])->filter()->implode(' · ');
    }

    /** @param  array{build: ?int, sha: ?string, built_at: ?string}|null  $info */
    public static function fake(?array $info): void
    {
        self::$cache = $info;
    }

    /** @return array{build: ?int, sha: ?string, built_at: ?string} */
    private static function read(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = base_path(self::FILE);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $data = is_array($data) ? $data : [];

        return self::$cache = [
            'build' => is_int($data['build'] ?? null) ? $data['build'] : null,
            'sha' => is_string($data['sha'] ?? null) ? $data['sha'] : null,
            'built_at' => is_string($data['built_at'] ?? null) ? $data['built_at'] : null,
        ];
    }
}

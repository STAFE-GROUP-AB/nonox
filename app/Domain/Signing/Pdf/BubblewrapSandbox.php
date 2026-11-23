<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * The bubblewrap (bwrap) command line that runs the PDF engine's helper with
 * nothing of the application in sight: no `.env`, no `storage/`, no base
 * path. Only the system libraries, the engine's own Python environment,
 * script and fonts are visible, read-only, and the call's own work
 * directory, read-write. Pure: it builds an argument list (never a shell
 * string) and touches no file, so it can be tested without bwrap.
 */
final readonly class BubblewrapSandbox
{
    /** Read-only system paths; --ro-bind-try skips the ones a distribution does not have. */
    private const array SYSTEM_PATHS = [
        '/usr', '/lib', '/lib64', '/bin', '/sbin',
        '/etc/ssl', '/etc/ca-certificates', '/etc/resolv.conf', '/etc/hosts', '/etc/nsswitch.conf',
        '/etc/ld.so.cache', '/etc/ld.so.conf', '/etc/ld.so.conf.d', '/etc/localtime',
    ];

    /**
     * @param  list<string>  $readOnlyPaths  the Python environment, the script's directory and the fonts directory
     */
    public function __construct(
        private string $bwrapPath,
        private array $readOnlyPaths,
    ) {}

    /**
     * The read-only paths of an engine: the venv root, the real interpreter's
     * prefix when it lies outside /usr, the script's directory and the fonts.
     *
     * @return list<string>
     */
    public static function pathsFor(string $python, string $script, string $fontsDirectory): array
    {
        $paths = [dirname($python, 2)];
        $real = realpath($python);

        if ($real !== false && ! str_starts_with($real, '/usr/') && ! str_starts_with($real, '/bin/')) {
            $paths[] = dirname($real, 2);
        }

        $paths[] = dirname($script);
        $paths[] = $fontsDirectory;

        return array_values(array_unique(array_filter($paths, fn (string $path): bool => $path !== '' && $path !== '/' && $path !== '.')));
    }

    /**
     * The first read-only path that would make the application, its storage, its .env or a home directory
     * visible in the sandbox (the path is, or contains, a protected one), or null. Paths inside
     * $allowedDirectory (the engine's own tools directory) are fine.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $protected
     */
    public static function unsafePath(array $paths, array $protected, string $allowedDirectory): ?string
    {
        $allowed = rtrim($allowedDirectory, '/');
        $real = realpath($allowed);

        foreach ($paths as $path) {
            $path = rtrim($path, '/');

            if (str_starts_with($path.'/', $allowed.'/') || ($real !== false && str_starts_with($path.'/', $real.'/'))) {
                continue;
            }

            foreach ($protected as $secret) {
                $secret = rtrim($secret, '/');

                if ($secret !== '' && str_starts_with($secret.'/', $path.'/')) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * $network is true only for the operations that must reach a timestamp server or a certificate
     * authority (S10, S11). Without it the network namespace is unshared, so the helper cannot reach
     * even 127.0.0.1 services.
     *
     * @param  list<string>  $command  what runs inside, e.g. [python, -I, script]
     * @return list<string>
     */
    public function command(string $work, array $command, bool $network = false): array
    {
        $argv = [
            $this->bwrapPath,
            '--die-with-parent',
            '--new-session',
            '--unshare-all',
        ];

        if ($network) {
            // Only the timestamp server (S10) and the OCSP/CRL fetches (S11) need the network. It is the
            // host's: services on 127.0.0.1 are reachable too, so they must require a password.
            $argv[] = '--share-net';
        }

        $argv[] = '--clearenv';

        foreach (self::SYSTEM_PATHS as $path) {
            array_push($argv, '--ro-bind-try', $path, $path);
        }

        array_push($argv, '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp');

        foreach ($this->readOnlyPaths as $path) {
            array_push($argv, '--ro-bind-try', $path, $path);
        }

        array_push($argv, '--bind', $work, $work, '--chdir', $work);
        array_push($argv, '--setenv', 'PATH', '/usr/local/bin:/usr/bin:/bin', '--setenv', 'LANG', 'C.UTF-8', '--setenv', 'LC_ALL', 'C.UTF-8', '--setenv', 'HOME', $work, '--setenv', 'TMPDIR', $work);
        $argv[] = '--';

        return [...$argv, ...$command];
    }
}

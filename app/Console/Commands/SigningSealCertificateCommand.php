<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Signing\Seal\SealCertificates;
use App\Domain\Signing\Seal\SelfSignedSeal;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Makes the instance's self-signed seal (ADR-046 amendment, gap 5, package
 * S10): an RSA key and a self-signed certificate as a PKCS#12 at
 * nonox.signing.seal.instance_pkcs12 (NONOX_SIGNING_SEAL_PKCS12), the file
 * 0600 in a 0700 directory, never inside the public web root. The password
 * is NONOX_SIGNING_SEAL_PASSWORD; when it is not set, a random one is made
 * and printed once, for the operator to put in .env. The key itself is
 * never printed, logged or audited.
 *
 * It replaces an existing file only with --force. Companies with their own
 * certificate are not affected; the rest seal with the new key from the
 * next completed signing.
 */
class SigningSealCertificateCommand extends Command
{
    protected $signature = 'signing:seal-certificate
        {--name= : the certificate\'s name (CN), by default the application name}
        {--organisation= : the organisation (O), for example the operator company}
        {--days=1095 : how many days it is valid (30–3650)}
        {--force : replace an existing seal file}';

    protected $description = 'Skapar installationens självsignerade förseglingscertifikat för signerade dokument (PAdES).';

    public function handle(): int
    {
        $path = SealCertificates::instancePath();

        if ($path === '') {
            $this->error('NONOX_SIGNING_SEAL_PKCS12 är tom: ange var filen ska ligga.');

            return self::FAILURE;
        }

        $directory = dirname($path);
        $public = realpath(public_path()) ?: public_path();

        if (str_starts_with((realpath($directory) ?: $directory).DIRECTORY_SEPARATOR, rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            $this->error('Filen får inte ligga i den publika webbroten. Välj en sökväg utanför public/.');

            return self::FAILURE;
        }

        if (file_exists($path) && ! $this->option('force')) {
            $this->error("Det finns redan ett förseglingscertifikat i {$path}. Använd --force för att ersätta det.");

            return self::FAILURE;
        }

        $days = (int) $this->option('days');

        if ($days < SelfSignedSeal::MIN_DAYS || $days > SelfSignedSeal::MAX_DAYS) {
            $this->error('--days måste vara mellan 30 och 3650.');

            return self::FAILURE;
        }

        $password = (string) config('nonox.signing.seal.instance_password', '');
        $generated = $password === '';

        if ($generated) {
            $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }

        $name = trim((string) ($this->option('name') ?: config('app.name', 'NONOX'))).' – försegling';
        $organisation = $this->option('organisation');

        try {
            $seal = SelfSignedSeal::make($name, is_string($organisation) ? $organisation : null, $days, $password);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->error("Katalogen {$directory} kunde inte skapas.");

            return self::FAILURE;
        }

        @chmod($directory, 0700);
        $temporary = $directory.DIRECTORY_SEPARATOR.'.seal-'.bin2hex(random_bytes(8));
        $previous = umask(0077);

        try {
            file_put_contents($temporary, $seal['pkcs12']);
            chmod($temporary, 0600);
            rename($temporary, $path);
        } finally {
            umask($previous);

            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        SealCertificates::forget();
        Audit::instance('signing.instance_seal_created', null, ['days' => $days, 'replaced' => (bool) $this->option('force')]);

        $this->info('Förseglingscertifikatet är skapat.');
        $this->line("Fil: {$path}");
        $this->line("Namn: {$seal['info']->subject}");
        $this->line('Giltigt till: '.$seal['info']->notAfter->setTimezone((string) config('app.timezone'))->format('Y-m-d'));
        $this->line("Fingeravtryck (SHA-256): {$seal['info']->fingerprintSha256}");

        if ($generated) {
            $this->warn('Lägg in lösenordet i .env, det visas bara nu:');
            $this->line("NONOX_SIGNING_SEAL_PASSWORD={$password}");
        }

        $this->comment('Försegling används först när den är påslagen i /hq och PDF-motorn är installerad.');

        return self::SUCCESS;
    }
}

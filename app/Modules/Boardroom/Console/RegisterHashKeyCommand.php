<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Console;

use Illuminate\Console\Command;

/**
 * Creates the key for the share register's hash chain (NONOX_REGISTER_HASH_KEY,
 * ADR-070). With --write it is written to .env, but only when no key is set
 * there: the key must never change, or every register's chain breaks. Run by
 * `composer setup`.
 */
class RegisterHashKeyCommand extends Command
{
    protected $signature = 'share-register:hash-key {--write : Skriv nyckeln till .env om den saknas}';

    protected $description = 'Skapar nyckeln till aktiebokens hashkedja (NONOX_REGISTER_HASH_KEY)';

    public function handle(): int
    {
        $key = bin2hex(random_bytes(32));

        if (! $this->option('write')) {
            $this->line("NONOX_REGISTER_HASH_KEY={$key}");
            $this->comment('Lägg raden i .env. Byt aldrig nyckeln när aktieböcker finns.');

            return self::SUCCESS;
        }

        $path = base_path('.env');
        $env = is_file($path) ? (string) file_get_contents($path) : '';

        if (preg_match('/^NONOX_REGISTER_HASH_KEY=\S+/m', $env) === 1) {
            $this->comment('Nyckeln till aktiebokens hashkedja finns redan och ändras inte.');

            return self::SUCCESS;
        }

        $env = preg_match('/^NONOX_REGISTER_HASH_KEY=$/m', $env) === 1
            ? (string) preg_replace('/^NONOX_REGISTER_HASH_KEY=$/m', "NONOX_REGISTER_HASH_KEY={$key}", $env)
            : rtrim($env)."\n\nNONOX_REGISTER_HASH_KEY={$key}\n";

        file_put_contents($path, $env);
        $this->info('Nyckeln till aktiebokens hashkedja är skapad i .env. Spara den med APP_KEY i säkerhetskopian.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Connect\Console;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

/**
 * Registers an app that may ask companies for access (ADR-032). Apps are
 * registered by whoever runs the installation, not by companies: a company
 * can only let in an app that exists here (and, if it wants, only the apps
 * it lists). Redirect URIs must be https, except http://localhost and
 * http://127.0.0.1 for development. A confidential app's secret is shown
 * once and stored hashed.
 */
final class ConnectAppCommand extends Command
{
    protected $signature = 'connect:app
        {name : The app\'s name, as people see it on the consent screen}
        {redirect* : One or more exact redirect URIs}
        {--public : A public client (mobile or desktop app) without a secret; PKCE protects it}';

    protected $description = 'Register an OAuth app for NONOX API';

    public function handle(ClientRepository $clients): int
    {
        /** @var list<string> $redirects */
        $redirects = array_values((array) $this->argument('redirect'));

        foreach ($redirects as $uri) {
            if (! self::validRedirect($uri)) {
                $this->error("Redirect URI {$uri} is not allowed: use https, or http only for localhost.");

                return self::FAILURE;
            }
        }

        $client = $clients->createAuthorizationCodeGrantClient((string) $this->argument('name'), $redirects, ! $this->option('public'));

        $this->info("Registered {$this->argument('name')}.");
        $this->line("client_id: {$client->getKey()}");

        if ($client->plainSecret !== null) {
            $this->line("client_secret: {$client->plainSecret}");
            $this->comment('The secret is shown once. Store it in the app, never in a repository.');
        }

        return self::SUCCESS;
    }

    public static function validRedirect(string $uri): bool
    {
        $parts = parse_url($uri);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return false;
        }

        if ($parts['scheme'] === 'https') {
            return true;
        }

        return $parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
    }
}

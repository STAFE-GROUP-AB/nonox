<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Checks that the installation can run, and explains the first thing that is
 * missing in plain Swedish for people who have never used a terminal.
 */
class SetupStatus
{
    private Migrator $migrator;

    /** @var array{title: string, explanation: string, command: string, details: string}|false|null */
    private array|false|null $problem = false;

    public function __construct()
    {
        $this->migrator = app('migrator');
    }

    public function isReady(): bool
    {
        return $this->problem() === null;
    }

    /** @return array{title: string, explanation: string, command: string, details: string}|null */
    public function problem(): ?array
    {
        if ($this->problem === false) {
            $this->problem = $this->databaseProblem()
                ?? $this->migrationProblem()
                ?? $this->assetProblem();
        }

        return $this->problem;
    }

    /** @return array{title: string, explanation: string, command: string, details: string}|null */
    private function databaseProblem(): ?array
    {
        try {
            DB::connection()->getPdo();
        } catch (PDOException $exception) {
            return $this->explainDatabaseError($exception);
        }

        return null;
    }

    /** @return array{title: string, explanation: string, command: string, details: string} */
    private function explainDatabaseError(PDOException $exception): array
    {
        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');
        $message = $exception->getMessage();
        $createDatabase = "psql -h {$host} -p {$port} -U {$username} -d postgres -c \"CREATE DATABASE {$database};\"";

        if (str_contains($message, "database \"{$database}\" does not exist")) {
            return [
                'title' => 'Databasen behöver skapas',
                'explanation' => 'Systemet sparar allt i en databas, och den finns inte ännu. Öppna Terminalen, klistra in raden nedan och tryck Enter. Ladda sedan om den här sidan.',
                'command' => $createDatabase,
                'details' => $message,
            ];
        }

        if (str_contains($message, "role \"{$username}\" does not exist")) {
            return [
                'title' => 'Fel användarnamn till databasen',
                'explanation' => "Databasen känner inte igen namnet \"{$username}\". På en Mac med Herd är det oftast ditt användarnamn på datorn. Kör raden nedan i Terminalen för att se det, skriv in det efter DB_USERNAME= i filen .env och ladda om sidan.",
                'command' => 'whoami',
                'details' => $message,
            ];
        }

        if (str_contains($message, 'password authentication failed')) {
            return [
                'title' => 'Fel lösenord till databasen',
                'explanation' => 'Databasen nekade inloggningen. Skriv rätt lösenord efter DB_PASSWORD= i filen .env och ladda om sidan.',
                'command' => 'open .env',
                'details' => $message,
            ];
        }

        return [
            'title' => 'Databasen är inte igång',
            'explanation' => 'Systemet får ingen kontakt med databasen PostgreSQL. Öppna Herd, gå till Services och starta PostgreSQL. Ladda sedan om den här sidan. Hjälper inte det: skapa databasen med raden nedan i Terminalen.',
            'command' => $createDatabase,
            'details' => $message,
        ];
    }

    /** @return array{title: string, explanation: string, command: string, details: string}|null */
    private function migrationProblem(): ?array
    {
        $files = $this->migrator->getMigrationFiles(
            array_merge($this->migrator->paths(), [database_path('migrations')])
        );

        $ran = $this->migrator->repositoryExists() ? $this->migrator->getRepository()->getRan() : [];
        $pending = count(array_diff(array_keys($files), $ran));

        if ($pending === 0) {
            return null;
        }

        return [
            'title' => 'Databasen behöver göras i ordning',
            'explanation' => 'Databasen finns, men tabellerna där allt ska sparas saknas eller behöver uppdateras. Kör raden nedan i Terminalen, i mappen där systemet ligger, och ladda sedan om sidan.',
            'command' => 'php artisan migrate',
            'details' => "{$pending} migreringar har inte körts.",
        ];
    }

    /** @return array{title: string, explanation: string, command: string, details: string}|null */
    private function assetProblem(): ?array
    {
        if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot'))) {
            return null;
        }

        return [
            'title' => 'Utseendet behöver byggas',
            'explanation' => 'Filerna som styr hur sidorna ser ut har inte byggts ännu. Kör raden nedan i Terminalen, i mappen där systemet ligger, och ladda sedan om sidan. Det tar någon minut.',
            'command' => 'npm install && npm run build',
            'details' => 'public/build/manifest.json saknas.',
        ];
    }
}

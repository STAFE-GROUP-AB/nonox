<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\DatabaseDoctor;
use App\Support\Database\DatabaseRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks that the role NONOX runs as cannot switch off the protection of the
 * books (ADR-079 §6). Read-only: it only reads PostgreSQL's catalogue.
 * Exits with 1 when it has a warning, so a deploy script can act on it.
 */
class DoctorCommand extends Command
{
    protected $signature = 'nonox:doctor';

    protected $description = 'Kontrollerar att databasrollen som NONOX körs som inte kan stänga av skydden för bokföringen (läser bara).';

    public function handle(): int
    {
        $owner = config('database.connections.'.DatabaseRoles::migrationConnection());
        $doctor = new DatabaseDoctor(DB::connection(), is_array($owner) ? $owner : []);

        $this->info("NONOX körs som databasrollen {$doctor->role()}.");

        $findings = $doctor->findings();

        if ($findings === []) {
            $this->info('OK: rollen äger inga tabeller, är inte superanvändare och kan inte ändra triggerna.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->warn("Varning: {$finding}");
        }

        $this->comment(count($findings).' varningar.');

        return self::FAILURE;
    }
}

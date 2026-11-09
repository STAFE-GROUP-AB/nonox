<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\DatabaseGrants;
use App\Support\Database\DatabaseRoles;
use App\Support\Database\TriggerFunctions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies the grant decisions (ADR-079 §6) as the owner, or writes them to
 * database/roles/grants.sql for psql. Every migrate also applies them when
 * the database roles are on, so this is for the first switch-over and repairs.
 */
class ApplyDatabaseGrantsCommand extends Command
{
    protected $signature = 'nonox:db-grants {--write : Skriv om database/roles/grants.sql i stället för att köra}';

    protected $description = 'Ger appens och läsarens databasroller exakt de behörigheter som besluten per tabell anger.';

    public function handle(): int
    {
        if ((bool) $this->option('write')) {
            file_put_contents(base_path(DatabaseGrants::FILE), DatabaseGrants::sql());
            $this->info('Skrev '.DatabaseGrants::FILE.' ('.count(DatabaseGrants::TABLES).' tabeller).');

            return self::SUCCESS;
        }

        if (! DatabaseRoles::enabled()) {
            $this->error('Databasrollerna är inte påslagna (DB_MIGRATION_USERNAME saknas). Se docs/drift/databasroller.md.');

            return self::FAILURE;
        }

        $connection = DB::connection(DatabaseRoles::migrationConnection());
        $this->info('Ger behörigheter som '.$connection->scalar('select current_user').' …');

        TriggerFunctions::pin($connection);
        DatabaseGrants::apply($connection);

        $undecided = DatabaseGrants::undecided($connection);

        if ($undecided !== []) {
            $this->warn('Tabeller utan beslut, appen får ingen åtkomst: '.implode(', ', $undecided).'.');

            return self::FAILURE;
        }

        $this->info('Klart: '.count(DatabaseGrants::TABLES).' tabeller har sina behörigheter.');

        return self::SUCCESS;
    }
}

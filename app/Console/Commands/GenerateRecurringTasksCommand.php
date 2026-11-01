<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\GenerateRecurringTasks;
use Illuminate\Console\Command;

/**
 * Creates this period's task for every recurring task, once per period
 * (ADR-028). Safe to run any number of times.
 */
class GenerateRecurringTasksCommand extends Command
{
    protected $signature = 'tasks:generate-recurring';

    protected $description = 'Skapar periodens uppgift för varje återkommande uppgift';

    public function handle(GenerateRecurringTasks $generate): int
    {
        $this->info('Går igenom de återkommande uppgifterna...');
        $created = $generate();
        $this->comment("{$created} uppgifter skapade.");

        return self::SUCCESS;
    }
}

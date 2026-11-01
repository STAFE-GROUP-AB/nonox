<?php

declare(strict_types=1);

namespace App\Modules\Connect\Console;

use App\Models\User;
use App\Modules\Connect\Actions\CreateTestCompany;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Creates a test company (Testföretag, ADR-032) with demo data for an
 * integrator who wants to try the API, owned by an existing user. The user
 * then signs in, creates an API key or connects an app under Anslutningar,
 * and works against invented data only.
 */
final class TestCompanyCommand extends Command
{
    protected $signature = 'connect:test-company
        {email : The e-mail address of the user who will own the test company}
        {--name= : The company name (default: Testföretaget AB)}';

    protected $description = 'Create a test company with demo data for trying the NONOX Connect API';

    public function handle(CreateTestCompany $create): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (! $user instanceof User) {
            $this->error("No user with the e-mail address {$this->argument('email')}. They sign up first.");

            return self::FAILURE;
        }

        $this->info("Creating a test company for {$user->email}...");

        try {
            $company = $create($user, $this->option('name') === null ? null : (string) $this->option('name'));
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->line("Company: {$company->name} (id {$company->id}, org.nr {$company->org_number})");
        $this->comment('Marked as a test company: shown with a banner, never e-mails documents, left out of statistics and billing.');
        $this->comment('Next: the owner signs in, opens Anslutningar and creates an API key or connects an app.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Company\AccountingFirmLink;
use App\Actions\Company\UpdateAccountingFirmSettings;
use App\Models\Company;
use App\Models\User;
use App\Support\Landing\Industries;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoCompanyBuilder;
use Database\Seeders\Demo\DemoExtra;
use Database\Seeders\Demo\DemoIndustries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * One demo company per industry page (/branscher), with this year's
 * bookkeeping up to yesterday: daily reports or customer invoices, supplier
 * invoices with approval and payment, salaries, VAT returns, employees and,
 * where the industry has one, a staff ledger. The two firm pages get an
 * accounting firm each, linked to its client companies.
 *
 * Run with `php artisan db:seed --class=IndustryDemoSeeder`. It only adds:
 * a company whose organisation number already exists is left as it is, so
 * running it again does nothing. Synthetic data only, and never on a server
 * that is reachable from the internet.
 *
 * Logins: Anna Andersson (anna.andersson@example.test, made up, so she can
 * be in the manual's screenshots) owns the companies and is an
 * administrator in the firms; each firm is owned by its own consultant,
 * who is Redovisningskonsult in the firm's clients. ADMIN_EMAIL, when set,
 * becomes an administrator in every demo company, to sign in locally.
 */
class IndustryDemoSeeder extends Seeder
{
    /** The consultant who owns each firm, by industry slug. */
    private const array FIRM_OWNERS = [
        'for-redovisningsbyraer' => ['name' => 'Maria Konsult', 'email' => 'redovisningsbyra@example.test'],
        'for-ekonomikonsulter' => ['name' => 'Eva Ekonomikonsult', 'email' => 'ekonomikonsult@example.test'],
    ];

    public const array OWNER = ['name' => 'Anna Andersson', 'email' => 'anna.andersson@example.test'];

    public function run(DemoCompanyBuilder $builder): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('IndustryDemoSeeder adds demo companies and must not run in production.');
        }

        // db:seed unguards every model; the Actions rely on $fillable, so they run as they do in the app.
        $unguarded = Model::isUnguarded();
        Model::reguard();

        try {
            $this->seed($builder);
        } finally {
            if ($unguarded) {
                Model::unguard();
            }
        }
    }

    private function seed(DemoCompanyBuilder $builder): void
    {
        $demos = DemoIndustries::all();
        $missing = array_diff(array_keys(Industries::all()), array_keys($demos));

        if ($missing !== []) {
            throw new RuntimeException('No demo company for the industry pages: '.implode(', ', $missing).'. Add them to DemoIndustries.');
        }

        $owner = $this->user(self::OWNER['name'], self::OWNER['email']);
        $current = $owner->current_team_id;
        $companies = [];
        /** @var list<array{0: string, 1: array<string, mixed>, 2: User, 3: Company}> $built */
        $built = [];
        $warn = fn (string $level, string $message) => $this->command?->warn("  {$message}");

        /** @var array<string, list<Company>> $clients */
        $clients = [];

        foreach ($demos as $slug => $demo) {
            $orgNumber = DemoIndustries::orgNumber($demo['org_serial'], '559', '9998');

            if (Company::query()->where('org_number', $orgNumber)->exists()) {
                $this->command?->line("  {$demo['name']} finns redan, hoppar över.");

                continue;
            }

            $this->command?->info("Bygger {$demo['name']} ({$slug}) …");

            $isFirm = $demo['is_firm'] ?? false;
            $user = $isFirm ? $this->user(self::FIRM_OWNERS[$slug]['name'], self::FIRM_OWNERS[$slug]['email']) : $owner;
            $company = $builder->build($slug, $user, $demo, $isFirm ? ($clients[$slug] ?? []) : [], $warn);
            $companies[] = $company;
            $built[] = [$slug, $demo, $user, $company];

            if (! $isFirm) {
                if (isset($demo['firm'])) {
                    $clients[$demo['firm']][] = $company;
                }

                continue;
            }

            $this->setUpFirm($company, $user, $owner, $clients[$slug] ?? []);
        }

        // Creating a company switches to it; the owner comes back to where they were.
        $owner->forceFill(['current_team_id' => $current ?? $owner->ownedTeams()->orderBy('id')->value('id')])->save();

        $this->addAdministrator($companies);

        foreach ($built as [$slug, $demo, $user, $company]) {
            foreach (DemoExtra::for($slug) as $extra) {
                $context = $builder->context($slug, $demos[$slug], $user, $company->fresh() ?? $company);
                CurrentCompany::run($company, fn () => $extra->afterAll($context));
            }
        }
    }

    /**
     * ADMIN_EMAIL as an administrator in the demo companies, so the person
     * who runs the seeder can sign in to them.
     *
     * @param  list<Company>  $companies
     */
    private function addAdministrator(array $companies): void
    {
        $email = config('app.admin_email');

        if (! is_string($email) || $email === '' || $email === self::OWNER['email'] || $companies === []) {
            return;
        }

        $admin = $this->user('Administratör', $email);

        foreach ($companies as $company) {
            if ($company->user_id !== $admin->id) {
                $company->users()->syncWithoutDetaching([$admin->id => ['role' => 'admin']]);
            }
        }

        if ($admin->current_team_id === null) {
            $admin->forceFill(['current_team_id' => $companies[0]->id])->save();
        }
    }

    /** @param  list<Company>  $clients */
    private function setUpFirm(Company $firm, User $consultant, User $owner, array $clients): void
    {
        app(UpdateAccountingFirmSettings::class)($consultant, $firm, true, false);
        $firm->users()->syncWithoutDetaching([$owner->id => ['role' => 'admin']]);

        foreach ($clients as $client) {
            $client->users()->syncWithoutDetaching([$consultant->id => ['role' => 'accountant']]);
            app(AccountingFirmLink::class)->link($owner, $client->fresh() ?? $client, $firm->id);
        }
    }

    private function user(string $name, string $email): User
    {
        return User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'email_verified_at' => now()]);
    }
}

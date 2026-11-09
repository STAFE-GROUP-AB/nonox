<?php

declare(strict_types=1);

namespace App\Actions\Demo;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoMode;
use App\Support\Luhn;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoCompanyBuilder;
use Database\Seeders\Demo\DemoExtra;
use Database\Seeders\Demo\DemoIndustries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Makes one visitor's demo (ADR-077): a guest user with a placeholder
 * address and no password, and a fresh copy of an industry demo company
 * that the guest owns, built by the same code as the developer seeders.
 *
 * All of it happens in one transaction, so a build that fails leaves
 * nothing behind. The company gets its own organisation number from a
 * range the developer demos do not use, is flagged as a test company (so
 * every rule for test companies applies) and, last, demo_sessions records
 * the time the demo ends. It all happens in the demo database, which is the
 * default connection while it runs. Nothing is blocked while it is built
 * (DemoMode::building).
 *
 * @phpstan-import-type Demo from DemoIndustries
 */
final class CreateDemo
{
    public function __construct(private readonly DemoCompanyBuilder $builder) {}

    public function __invoke(string $slug, CarbonImmutable $expiresAt): User
    {
        $demos = DemoIndustries::all();

        if (! array_key_exists($slug, DemoCatalog::industries()) || ! isset($demos[$slug])) {
            throw new InvalidArgumentException("No demo for {$slug}.");
        }

        // The web guards models; a seeder run from the command line unguards them. The Actions rely on $fillable.
        $unguarded = Model::isUnguarded();
        Model::reguard();

        try {
            return DemoMode::building(fn (): User => DB::transaction(fn (): User => $this->create($slug, $demos[$slug], $expiresAt)));
        } finally {
            if ($unguarded) {
                Model::unguard();
            }

        }
    }

    /**
     * @param  Demo  $demo
     */
    private function create(string $slug, array $demo, CarbonImmutable $expiresAt): User
    {
        $guest = User::query()->create([
            'name' => 'Gäst',
            'email' => 'gast-'.Str::lower(Str::random(20)).'@'.DemoMode::GUEST_DOMAIN,
            'email_verified_at' => now(),
        ]);

        $demo['org_number'] = $this->orgNumber();
        $company = $this->builder->build($slug, $guest, $demo, [], fn (string $level, string $message) => null);

        foreach (DemoExtra::for($slug) as $extra) {
            $context = $this->builder->context($slug, $demo, $guest, $company->fresh() ?? $company);
            CurrentCompany::run($company, fn () => $extra->afterAll($context));
        }

        $guest->forceFill(['current_team_id' => $company->id])->save();

        DB::table('demo_sessions')->insert([
            'user_id' => $guest->id,
            'company_id' => $company->id,
            'expires_at' => $expiresAt,
        ]);

        return $guest;
    }

    /**
     * "00" and seven random digits with a Luhn check digit: a group number no real
     * Swedish organisation has (the same range as the API's test companies,
     * ADR-032), so a demo can never take a real company's number, unused in the demo database.
     */
    private function orgNumber(): string
    {
        do {
            $digits = '00'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
            $number = OrganisationNumber::tryFrom($digits.Luhn::checkDigit($digits))?->formatted();
        } while ($number === null || CompanyScope::bypass(fn (): bool => Company::query()->where('org_number', $number)->exists()));

        return $number;
    }
}

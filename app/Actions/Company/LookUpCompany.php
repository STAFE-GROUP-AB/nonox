<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyRegistry\CompanyRegistries;
use App\Support\CompanyRegistry\CompanyRegistry;
use App\Support\CompanyRegistry\RegisteredCompany;
use App\Support\CompanyRegistry\RegistryUnavailable;
use App\Support\WorkQuota;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Looks an organisation number up in the installation's company register
 * (ADR-035) and returns what it says, for a form to propose. Nothing is
 * saved here: the user sees the answer and saves the form.
 *
 * The number is checked with Luhn first, so a typo never reaches the
 * register. Lookups are capped per company and hour (WorkQuota), per user
 * and hour (also before a company exists, in onboarding) and per minute
 * for the whole installation, below Bolagsverket's limit of 60 requests a
 * minute per client. Answers are cached briefly, keyed on a hash so a
 * sole trader's personal number is not readable in the cache.
 */
class LookUpCompany
{
    public const int USER_LIMIT_PER_HOUR = 60;

    public const int INSTALLATION_LIMIT_PER_MINUTE = 50;

    /** @throws ValidationException */
    public function __invoke(User $user, ?Company $company, string $input, string $field = 'org_number'): RegisteredCompany
    {
        $registry = CompanyRegistries::make();

        if ($registry === null) {
            throw ValidationException::withMessages([$field => __('Hämtning av företagsuppgifter är inte påslagen i den här installationen.')]);
        }

        $number = OrganisationNumber::tryFrom($input);

        if ($number === null) {
            throw ValidationException::withMessages([$field => __('Organisationsnumret är inte giltigt. Kontrollera siffrorna, så går det att hämta uppgifterna.')]);
        }

        $this->takeQuota($user, $company, $field);

        $identity = self::identity($number, $input);

        try {
            $found = $this->find($registry, $identity);
        } catch (RegistryUnavailable $exception) {
            report($exception);

            throw ValidationException::withMessages([$field => __(':source svarar inte just nu. Fyll i uppgifterna själv eller försök igen om en stund.', ['source' => $registry->sourceName()])]);
        }

        if ($found === []) {
            throw ValidationException::withMessages([$field => __(':source hittar inget företag med organisationsnumret :number.', ['source' => $registry->sourceName(), 'number' => $number->formatted()])]);
        }

        return collect($found)->first(fn (RegisteredCompany $candidate): bool => $candidate->isActive()) ?? $found[0];
    }

    /**
     * What to send: an organisation number as ten digits, a personal
     * number (a sole trader's) as twelve with the century, as the register
     * expects. The third digit of an organisation number is 2 or more; of
     * a personal number 0 or 1 (the month). Without a century in the input
     * the most recent one that does not lie in the future is used, one
     * further back when the number was written with "+" (100 years or older).
     */
    public static function identity(OrganisationNumber $number, string $input): string
    {
        $digits = $number->digits;

        if ((int) $digits[2] >= 2) {
            return $digits;
        }

        $typed = preg_replace('/[^\d]/', '', $input) ?? '';

        if (strlen($typed) === 12) {
            return $typed;
        }

        $today = CarbonImmutable::today();
        $century = intdiv($today->year, 100);

        if ((int) substr($digits, 0, 2) > $today->year % 100) {
            $century--;
        }

        if (str_contains($input, '+')) {
            $century--;
        }

        return sprintf('%02d%s', $century, $digits);
    }

    /** @return list<RegisteredCompany> */
    private function find(CompanyRegistry $registry, string $identity): array
    {
        $cacheKey = "company-registry:{$registry->key()}:".hash('sha256', $identity);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return array_values(array_map(fn (mixed $entry): RegisteredCompany => RegisteredCompany::fromArray((array) $entry), $cached));
        }

        $found = $registry->find($identity);

        Cache::put($cacheKey, array_map(fn (RegisteredCompany $entry): array => $entry->toArray(), $found), now()->addMinutes(max(1, (int) config('services.company_registry.cache_minutes', 10))));

        return $found;
    }

    /** @throws ValidationException */
    private function takeQuota(User $user, ?Company $company, string $field): void
    {
        if ($company !== null) {
            WorkQuota::take($company, 'company_lookup', $field);
        }

        foreach ([
            ["company-lookup:user:{$user->id}", self::USER_LIMIT_PER_HOUR, 3600],
            ['company-lookup:installation', self::INSTALLATION_LIMIT_PER_MINUTE, 60],
        ] as [$key, $limit, $seconds]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw ValidationException::withMessages([$field => WorkQuota::message()]);
            }

            RateLimiter::hit($key, $seconds);
        }
    }
}

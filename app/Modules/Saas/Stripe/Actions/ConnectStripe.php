<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Saas\Stripe\Jobs\SyncStripe;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Support\StripeClient;
use App\Modules\Saas\Stripe\Support\StripeFailure;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;

/**
 * Connects the company's own Stripe account with a restricted read-only key
 * the company pastes in (ADR-080 §7.1), or replaces the key of the active
 * connection after the same check (key rotation, §7.7).
 *
 * - A secret key (sk_) or a publishable key (pk_) is refused: a full secret
 *   key gives write access the company did not mean to grant, and Stripe
 *   calls sharing one with a third party "Dangerous"
 *   (docs.stripe.com/keys/restricted-api-keys). A test key (rk_test_) is
 *   refused outside local and test environments.
 * - One read call per resource NONOX needs; a 403 names the missing
 *   permission in Swedish. Every call is made before anything is written.
 * - The account id (acct_…) and the last four characters are stored; the
 *   key itself only encrypted, never logged and never shown again.
 * - A Stripe account is connected to one company at a time; reconnecting the
 *   same account in the same company takes up the earlier connection, with
 *   its customer links, and reads the account in full again.
 */
class ConnectStripe
{
    /**
     * The read permissions, per request (TODO(verify): the exact labels in
     * Stripe's key form, by creating a key in a sandbox, ADR-080 §7.1).
     *
     * @var array<string, string>
     */
    public const array PERMISSIONS = [
        '/v1/account' => 'Account',
        '/v1/customers' => 'Customers',
        '/v1/products' => 'Products',
        '/v1/prices' => 'Prices',
        '/v1/coupons' => 'Coupons',
        '/v1/subscriptions' => 'Subscriptions',
        '/v1/events' => 'Events',
    ];

    public function __invoke(User $user, Company $company, #[SensitiveParameter] string $key): StripeConnection
    {
        StripePermissions::authorizeConnect($user, $company);
        $key = trim($key);
        $live = self::checkFormat($key);

        // Every request first, outside any transaction (step-1 follow-up).
        $account = $this->verify($key);

        return CurrentCompany::run($company, function () use ($user, $company, $key, $live, $account): StripeConnection {
            try {
                return DB::transaction(function () use ($user, $company, $key, $live, $account): StripeConnection {
                    $active = StripeConnection::query()->where('status', StripeConnection::ACTIVE)->lockForUpdate()->first();

                    if ($active !== null) {
                        if ($active->account_id !== $account) {
                            throw ValidationException::withMessages(['key' => __('Nyckeln hör till ett annat Stripe-konto än det som är kopplat. Koppla från först om du vill byta konto.')]);
                        }

                        $active->forceFill(['secret_key' => $key, 'key_last4' => substr($key, -4), 'livemode' => $live, 'key_version' => $active->key_version + 1])->save();
                        Audit::record('stripe.key_rotated', $active, ['connection' => $active->id], $company, $user);

                        return $active;
                    }

                    // The partial unique index decides as well; this gives the Swedish answer first.
                    $elsewhere = CompanyScope::bypass(fn (): bool => StripeConnection::query()
                        ->where('status', StripeConnection::ACTIVE)
                        ->where('account_id', $account)
                        ->where('company_id', '!=', $company->id)
                        ->exists());

                    if ($elsewhere) {
                        throw self::takenElsewhere();
                    }

                    $connection = StripeConnection::query()->where('account_id', $account)->orderByDesc('id')->first() ?? new StripeConnection;
                    $connection->forceFill([
                        'company_id' => $company->id,
                        'status' => StripeConnection::ACTIVE,
                        'account_id' => $account,
                        'livemode' => $live,
                        'secret_key' => $key,
                        'key_last4' => substr($key, -4),
                        'webhook_token' => $connection->webhook_token ?? Str::random(48),
                        'webhook_secret' => null,
                        'connected_by' => $user->id,
                        'connected_at' => now(),
                        'ended_at' => null,
                        'ended_by' => null,
                        'end_reason' => null,
                        'initial_import_done_at' => null,
                        'cursor' => null,
                        'consecutive_failures' => 0,
                        'key_version' => $connection->exists ? $connection->key_version + 1 : 1,
                    ])->save();

                    Audit::record('stripe.connected', $connection, ['connection' => $connection->id, 'livemode' => $live], $company, $user);
                    SyncStripe::dispatch($company->id, $connection->id)->afterCommit();

                    return $connection;
                });
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'stripe_connections_account_active')) {
                    throw self::takenElsewhere();
                }

                // Rethrown without the query: its bindings hold the encrypted key and the webhook token (review P3-1).
                throw new RuntimeException('The Stripe connection could not be saved.');
            }
        });
    }

    /** @return bool whether it is a live key */
    public static function checkFormat(#[SensitiveParameter] string $key): bool
    {
        if (str_starts_with($key, 'sk_') || str_starts_with($key, 'pk_')) {
            throw ValidationException::withMessages(['key' => __('Det här är inte en begränsad nyckel. Skapa en begränsad nyckel (den börjar med rk_live_) med bara läsbehörighet, och klistra in den. En hemlig nyckel (sk_) ger full åtkomst till ditt Stripe-konto och ska inte lämnas ut.')]);
        }

        if (preg_match('/^rk_(live|test)_[A-Za-z0-9]{10,255}$/', $key, $match) !== 1) {
            throw ValidationException::withMessages(['key' => __('Nyckeln ska börja med rk_live_ och kopieras hel från Stripe.')]);
        }

        if ($match[1] === 'test' && ! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages(['key' => __('En testnyckel (rk_test_) går bara att använda i en testinstallation. Skapa nyckeln i live-läget i Stripe.')]);
        }

        return $match[1] === 'live';
    }

    /** @return string the account id */
    private function verify(#[SensitiveParameter] string $key): string
    {
        $client = new StripeClient($key);
        $missing = [];
        $account = null;

        foreach (self::PERMISSIONS as $path => $label) {
            try {
                $body = $path === '/v1/account' ? $client->get($path) : $client->get($path, ['limit' => 1]);
            } catch (StripeFailure $failure) {
                if ($failure->reason === 'forbidden') {
                    $missing[] = $label;

                    continue;
                }

                throw ValidationException::withMessages(['key' => match ($failure->reason) {
                    'unauthorized' => __('Stripe godtar inte nyckeln. Kontrollera att du kopierade hela nyckeln och att den inte har gått ut.'),
                    'rate_limited', 'unavailable' => __('Stripe svarar inte just nu. Försök igen om en stund.'),
                    default => __('Stripe kunde inte läsa med nyckeln. Försök igen eller skapa en ny begränsad nyckel.'),
                }]);
            }

            if ($path === '/v1/account') {
                $account = is_string($body['id'] ?? null) ? $body['id'] : null;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages(['key' => __('Nyckeln saknar läsbehörighet för :resources. Ändra nyckeln i Stripe så att de har Read, och försök igen.', ['resources' => implode(', ', $missing)])]);
        }

        if ($account === null || preg_match('/^acct_[A-Za-z0-9]{1,60}$/', $account) !== 1) {
            throw ValidationException::withMessages(['key' => __('Stripe uppgav inget konto för nyckeln. Försök igen.')]);
        }

        return $account;
    }

    private static function takenElsewhere(): ValidationException
    {
        return ValidationException::withMessages(['key' => __('Det här Stripe-kontot är redan kopplat till ett annat företag i NONOX. Ett Stripe-konto kan vara kopplat till ett företag i taget.')]);
    }
}

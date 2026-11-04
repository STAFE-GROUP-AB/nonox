<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Crm\Actions\SendForSigning;
use App\Modules\Crm\Domain\SigningStatus;
use App\Modules\Crm\Models\SigningRequest;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoIndustries;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Takes the manual's screenshots (docs/manual/skarmbilder.yaml) from the
 * demo companies of IndustryDemoSeeder, through a running copy of the app
 * on the same demo database. It signs the demo owner in with a session of
 * its own, switches company per group of images and lets
 * scripts/manual-screenshots.mjs drive Chrome. Local only: the images must
 * never show real data, so an image is saved only when the demo company's
 * name is on the page.
 *
 * Start the app with a cache of its own, or it reads names cached by the
 * app on the real database (same ids, same cache files):
 *
 *   cd public && DB_DATABASE=nonox_demo CACHE_STORE=array OTP_AUTOFILL=false PASSKEYS_SKIP_LOCALLY=true \
 *     APP_URL=http://127.0.0.1:8123 php -S 127.0.0.1:8123 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
 *
 * Not `php artisan serve`: it does not pass DB_DATABASE on to the server, which
 * then reads .env and the real database.
 *
 * @phpstan-type Shot array{foretag: string, rutt: string, modell?: string|array{tabell: string, dar?: array<string, scalar>}, fraga?: array<string, string>, enhet?: string, del?: string, klick?: list<string>, visa?: string, anvandare?: string, text?: string}
 */
class ManualScreenshotsCommand extends Command
{
    protected $signature = 'manual:screenshots
        {--url=http://127.0.0.1:8123 : Adressen där appen körs mot demodatabasen}
        {--only= : Bara bilderna vars namn börjar så här, till exempel "bokforing/"}';

    protected $description = 'Tar skärmbilderna till manualen från demoföretagen.';

    /** @var array<int, ?string> the public signing link made for each company in this run */
    private array $signingLinks = [];

    /** The demo companies by the short names the list uses. */
    private const array COMPANIES = [
        'restaurang' => 'bokforing-for-restauranger',
        'cafe' => 'bokforing-for-cafeer',
        'butik' => 'bokforing-for-butiker',
        'frisor' => 'bokforing-for-frisorer',
        'hantverkare' => 'bokforing-for-hantverkare',
        'konsult' => 'bokforing-for-konsulter',
        'byra' => 'for-redovisningsbyraer',
        'ekonomikonsult' => 'for-ekonomikonsulter',
    ];

    private const array DEVICES = [
        'dator' => ['width' => 1280, 'height' => 800, 'scale' => 2, 'mobile' => false],
        'mobil' => ['width' => 390, 'height' => 844, 'scale' => 3, 'mobile' => true],
    ];

    public function handle(Encrypter $encrypter): int
    {
        if (app()->isProduction()) {
            $this->error('Skärmbilderna tas bara lokalt, från demoföretagen.');

            return self::FAILURE;
        }

        /** @var array<string, Shot> $shots */
        $shots = Yaml::parseFile(base_path('docs/manual/skarmbilder.yaml'));
        $only = (string) $this->option('only');
        $shots = array_filter($shots, fn (string $name): bool => $only === '' || str_starts_with($name, $only), ARRAY_FILTER_USE_KEY);
        $base = rtrim((string) $this->option('url'), '/');
        $companies = $this->companies();
        $owner = User::query()->findOrFail($companies['restaurang']->user_id);
        $admin = config('app.admin_email');
        $forbid = is_string($admin) && $admin !== '' && $admin !== $owner->email ? [$admin] : [];
        $cookies = [];
        $failed = false;

        /** @var array<int, int|null> $restore the users' companies before the run */
        $restore = [];

        try {
            $groups = collect($shots)->groupBy(fn (array $shot): string => $shot['foretag'].'|'.($shot['anvandare'] ?? ''), preserveKeys: true);

            foreach ($groups as $key => $group) {
                [$short, $login] = explode('|', (string) $key);
                $company = $companies[$short] ?? throw new RuntimeException("Okänt demoföretag: {$short}.");
                $user = match ($login) {
                    '', 'agare' => $owner,
                    'gast' => null,
                    default => $this->demoUser($login),
                };

                if ($user !== null) {
                    $restore[$user->id] ??= $user->current_team_id;
                    $user->forceFill(['current_team_id' => $company->id])->save();
                    $cookies[$user->id] ??= $this->signIn($user, $encrypter);
                }

                $this->info("{$company->name}".($login === '' ? '' : " ({$login})").": {$group->count()} bilder");

                $plan = [
                    'chrome' => config('services.chrome.path'),
                    'cookies' => $user === null ? [] : [['name' => (string) config('session.cookie'), 'value' => $cookies[$user->id], 'url' => $base]],
                    'shots' => $group->map(fn (array $shot, string $name): array => [
                        'url' => $base.$this->path($shot, $company),
                        'file' => public_path("bilder/manual/{$name}.webp"),
                        ...self::DEVICES[$shot['enhet'] ?? 'dator'],
                        'selector' => $shot['del'] ?? null,
                        'clicks' => $shot['klick'] ?? [],
                        'reveal' => $shot['visa'] ?? null,
                        'expect' => $shot['text'] ?? $company->name,
                        'forbid' => $forbid,
                    ])->values()->all(),
                ];

                $file = storage_path('framework/manual-screenshots.json');
                file_put_contents($file, json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $result = Process::forever()->run(['node', base_path('scripts/manual-screenshots.mjs'), $file], function (string $type, string $output): void {
                    $this->output->write($output);
                });
                @unlink($file);
                $failed = $failed || $result->failed();
            }
        } finally {
            foreach ($restore as $id => $team) {
                User::query()->whereKey($id)->update(['current_team_id' => $team]);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, Company> */
    private function companies(): array
    {
        $demos = DemoIndustries::all();
        $companies = [];

        foreach (self::COMPANIES as $short => $slug) {
            $companies[$short] = Company::query()->where('org_number', DemoIndustries::orgNumber($demos[$slug]['org_serial'], '559', '9998'))->first()
                ?? throw new RuntimeException("Demoföretaget för {$slug} finns inte. Kör först: php artisan db:seed --class=IndustryDemoSeeder");
        }

        return $companies;
    }

    /** A made-up demo login, never a real person's. */
    private function demoUser(string $email): User
    {
        if (! str_ends_with($email, '@example.test')) {
            throw new RuntimeException("Skärmbilder tas bara som påhittade användare (@example.test), inte som {$email}.");
        }

        return User::query()->where('email', $email)->firstOrFail();
    }

    /** A session of its own for a demo user, as the encrypted cookie the browser sends. */
    private function signIn(User $user, Encrypter $encrypter): string
    {
        $session = app('session')->driver();
        $session->setId(Str::random(40));
        $session->start();
        $session->put(Auth::guard('web')->getName(), $user->getKey());
        $session->save();

        $name = (string) config('session.cookie');

        return $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()).$session->getId(), false);
    }

    /** @param  Shot  $shot */
    private function path(array $shot, Company $company): string
    {
        $parameters = isset($shot['modell']) ? [$this->model($shot['modell'], $company)] : [];
        $url = route($shot['rutt'], $parameters, false);

        return isset($shot['fraga']) ? $url.'?'.http_build_query($shot['fraga']) : $url;
    }

    /** @param  string|array{tabell: string, dar?: array<string, scalar>}  $kind */
    private function model(string|array $kind, Company $company): int|string
    {
        if ($kind === 'company') {
            return $company->id;
        }

        // The newest row of a table in the company, matching the conditions.
        if (is_array($kind)) {
            $id = DB::table($kind['tabell'])->where('company_id', $company->id)->where($kind['dar'] ?? [])->orderByDesc('id')->value('id');

            return is_int($id) || is_string($id) ? $id : throw new RuntimeException("Hittade ingen rad i {$kind['tabell']} för {$company->name}.");
        }

        return CurrentCompany::run($company, fn (): int|string => match ($kind) {
            'voucher' => Voucher::query()->where('status', VoucherStatus::Posted)->has('rows', '>=', 4)->latest('voucher_date')->value('id'),
            'invoice' => Invoice::query()->where('status', InvoiceStatus::Issued)->latest('invoice_date')->value('id'),
            'rot_invoice' => Invoice::query()->where('tax_reduction_ore', '>', 0)->latest('invoice_date')->value('id'),
            'reverse_charge_invoice' => Invoice::query()->where('reverse_charge', true)->latest('invoice_date')->value('id'),
            'supplier_invoice_to_approve' => SupplierInvoice::query()->whereNull('approved_at')->where('status', '!=', 'DRAFT')->latest('invoice_date')->value('id'),
            // CRM signing (ADR-046): a quote that was signed, and a fresh link to the public page of the newest open quote out for signing.
            'signed_quote' => SigningRequest::query()->whereNotNull('sales_document_id')->where('status', SigningStatus::Signed->value)->latest('id')->value('sales_document_id'),
            'signing_link' => $this->signingLink($company),
            default => null,
        } ?? throw new RuntimeException("Hittade ingen {$kind} i {$company->name}."));
    }

    /**
     * Only the hash of a signing link's token is stored, so the public page
     * is reached through a new link for the newest open quote that is out
     * for signing; the old link for it is revoked, as by "Skicka igen".
     */
    private function signingLink(Company $company): ?string
    {
        // One link per company and run: a second one would revoke the first before its picture is taken.
        if (array_key_exists($company->id, $this->signingLinks)) {
            return $this->signingLinks[$company->id];
        }

        $request = SigningRequest::query()->whereNotNull('sales_document_id')->where('status', SigningStatus::Pending->value)->latest('id')->first();
        $quote = $request?->quote;
        $owner = User::query()->find($company->user_id);

        if ($request === null || ! $quote instanceof SalesDocument || ! $owner instanceof User) {
            return null;
        }

        $sent = app(SendForSigning::class)($owner, $company, $quote, ['email' => $request->email, 'deal_id' => $request->deal_id]);

        return $this->signingLinks[$company->id] = basename((string) parse_url($sent['url'], PHP_URL_PATH));
    }
}

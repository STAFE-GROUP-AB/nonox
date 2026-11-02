<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoIndustries;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Auth;
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
 *   DB_DATABASE=nonox_demo CACHE_STORE=array APP_URL=http://127.0.0.1:8123 php artisan serve --port=8123
 *
 * @phpstan-type Shot array{foretag: string, rutt: string, modell?: string, fraga?: array<string, string>, enhet?: string, del?: string}
 */
class ManualScreenshotsCommand extends Command
{
    protected $signature = 'manual:screenshots
        {--url=http://127.0.0.1:8123 : Adressen där appen körs mot demodatabasen}
        {--only= : Bara bilderna vars namn börjar så här, till exempel "bokforing/"}';

    protected $description = 'Tar skärmbilderna till manualen från demoföretagen.';

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
        $current = $owner->current_team_id;
        $cookie = $this->signIn($owner, $encrypter);
        $failed = false;

        try {
            foreach (collect($shots)->groupBy(fn (array $shot): string => $shot['foretag'], preserveKeys: true) as $short => $group) {
                $company = $companies[$short] ?? throw new RuntimeException("Okänt demoföretag: {$short}.");
                $owner->forceFill(['current_team_id' => $company->id])->save();
                $this->info("{$company->name}: {$group->count()} bilder");

                $plan = [
                    'chrome' => config('services.chrome.path'),
                    'cookies' => [['name' => (string) config('session.cookie'), 'value' => $cookie, 'url' => $base]],
                    'shots' => $group->map(fn (array $shot, string $name): array => [
                        'url' => $base.$this->path($shot, $company),
                        'file' => public_path("manual/bilder/{$name}.webp"),
                        ...self::DEVICES[$shot['enhet'] ?? 'dator'],
                        'selector' => $shot['del'] ?? null,
                        'expect' => $company->name,
                        'forbid' => array_values(array_filter([config('app.admin_email'), $current === null ? null : Company::query()->whereKey($current)->value('name')], fn (mixed $word): bool => is_string($word) && $word !== '' && ! str_contains($word, 'Demo'))),
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
            $owner->forceFill(['current_team_id' => $current])->save();
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

    /** A session of its own for the demo owner, as the encrypted cookie the browser sends. */
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

    private function model(string $kind, Company $company): int|string
    {
        if ($kind === 'company') {
            return $company->id;
        }

        return CurrentCompany::run($company, fn (): int|string => match ($kind) {
            'voucher' => Voucher::query()->where('status', VoucherStatus::Posted)->has('rows', '>=', 4)->latest('voucher_date')->value('id'),
            'invoice' => Invoice::query()->where('status', InvoiceStatus::Issued)->latest('invoice_date')->value('id'),
            'rot_invoice' => Invoice::query()->where('tax_reduction_ore', '>', 0)->latest('invoice_date')->value('id'),
            'reverse_charge_invoice' => Invoice::query()->where('reverse_charge', true)->latest('invoice_date')->value('id'),
            'supplier_invoice_to_approve' => SupplierInvoice::query()->whereNull('approved_at')->where('status', '!=', 'DRAFT')->latest('invoice_date')->value('id'),
            default => null,
        } ?? throw new RuntimeException("Hittade ingen {$kind} i {$company->name}."));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Models\Account;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Ai\Agents\SupplierInvoiceAgent;
use App\Modules\Ai\Agents\VoucherAgent;
use App\Modules\Ai\AiModule;
use App\Modules\Ai\Support\GozeroCore\AnalysisReceipt;
use App\Modules\Ai\Support\GozeroCore\GozeroCoreException;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Laravel\Ai\Ai;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

/**
 * Analyses a document with the company's AI provider and returns a proposal
 * for a person to check: nothing is saved or posted here. Accounts the
 * company's chart lacks are dropped, and a supplier is only suggested when
 * it matches one already in the register. The audit log records that an
 * analysis was made, never the document or its content.
 */
class AnalyzeDocument
{
    private const array IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly ModuleManager $modules) {}

    public static function availableFor(User $user, Company $company): bool
    {
        return app(ModuleManager::class)->isEnabled(AiModule::KEY, $company)
            && $user->hasTeamPermission($company, 'ai:use');
    }

    /**
     * @return array<string, mixed> the agent's answer, with supplier_id and only accounts in the chart
     */
    public function supplierInvoice(User $user, Company $company, string $path, string $mime): array
    {
        $answer = $this->ask($user, $company, new SupplierInvoiceAgent, $path, $mime, 'supplier_invoice');

        return CurrentCompany::run($company, function () use ($answer): array {
            $accounts = $this->accountNumbers();
            $answer['rows'] = array_values(array_filter((array) ($answer['rows'] ?? []), fn (mixed $row): bool => in_array((int) ((array) $row)['account'], $accounts, true)));
            $answer['supplier_id'] = $this->matchSupplier($answer);

            return $answer;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function voucher(User $user, Company $company, string $path, string $mime): array
    {
        $answer = $this->ask($user, $company, new VoucherAgent, $path, $mime, 'voucher');

        return CurrentCompany::run($company, function () use ($answer): array {
            $accounts = $this->accountNumbers();
            $answer['rows'] = array_values(array_filter((array) ($answer['rows'] ?? []), fn (mixed $row): bool => in_array((int) ((array) $row)['account'], $accounts, true)));

            return $answer;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(User $user, Company $company, SupplierInvoiceAgent|VoucherAgent $agent, string $path, string $mime, string $kind): array
    {
        $this->modules->ensureEnabled(AiModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'ai:use')) {
            throw new AuthorizationException;
        }

        $settings = $this->modules->settings(AiModule::KEY, $company);
        $key = $settings['api_key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new GozeroCoreException(__('NONOX AI saknar API-nyckel. Lägg in företagets nyckel under Företagsinställningar → Moduler → NONOX AI.'), 'key.missing');
        }

        $file = $mime === 'application/pdf'
            ? Document::fromPath($path)
            : (in_array($mime, self::IMAGE_TYPES, true) ? Image::fromPath($path, $mime) : throw new GozeroCoreException(__('Filen går inte att analysera. Använd PDF eller en bild i JPEG, PNG eller WebP.'), 'input.unsupported'));

        $provider = Ai::build([
            'name' => "nonox_ai_company_{$company->id}",
            'driver' => 'gozerocore',
            'key' => Crypt::decryptString($key),
            'url' => config('services.gozerocore.url'),
            'timeout' => config('services.gozerocore.timeout'),
            'quality' => (string) ($settings['quality'] ?? 'standard'),
        ]);

        $response = $agent->prompt($this->context($company), attachments: [$file], provider: $provider->name(), timeout: (int) config('services.gozerocore.timeout'));
        $receipt = app(AnalysisReceipt::class);

        Audit::record('ai.document_analyzed', null, ['kind' => $kind, 'provider' => $settings['provider'] ?? 'GOZERO_CORE', 'job_id' => $receipt->jobId, 'units' => $receipt->units, 'confidence' => $receipt->confidence], $company, $user);

        return [...(array) $response->toArray(), 'confidence' => $receipt->confidence, 'job_id' => $receipt->jobId];
    }

    /** The company's chart of accounts, for the agent to choose from. */
    private function context(Company $company): string
    {
        $chart = CurrentCompany::run($company, fn () => Account::query()->where('is_active', true)->orderBy('number')->get(['number', 'name']));

        return "Chart of accounts (number name):\n".$chart->map(fn (Account $account): string => "{$account->number} {$account->name}")->implode("\n");
    }

    /** @return list<int> */
    private function accountNumbers(): array
    {
        return array_values(Account::query()->where('is_active', true)->pluck('number')->map(fn (mixed $number): int => (int) $number)->all());
    }

    /**
     * The register's supplier with the same organisation number, bankgiro or
     * plusgiro; null when there is none, so a person adds it.
     *
     * @param  array<string, mixed>  $answer
     */
    private function matchSupplier(array $answer): ?int
    {
        $digits = fn (mixed $value): string => (string) preg_replace('/\D/', '', (string) $value);

        foreach (['org_number' => 'supplier_org_number', 'bankgiro' => 'supplier_bankgiro', 'plusgiro' => 'supplier_plusgiro'] as $column => $field) {
            $wanted = $digits($answer[$field] ?? '');

            if (strlen($wanted) < 5) {
                continue;
            }

            $match = Supplier::query()->whereNotNull($column)->get(['id', $column])->first(fn (Supplier $supplier): bool => $digits($supplier->getAttribute($column)) === $wanted);

            if ($match instanceof Supplier) {
                return $match->id;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Models\Account;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Ai\Agents\ExpenseReceiptAgent;
use App\Modules\Ai\Agents\SupplierInvoiceAgent;
use App\Modules\Ai\Agents\VoucherAgent;
use App\Modules\Ai\AiModule;
use App\Modules\Ai\Support\AiAnalysisException;
use App\Modules\Ai\Support\AiProvider;
use App\Modules\Ai\Support\HeicImage;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * Analyses a document with the company's AI provider (OpenAI, Anthropic or
 * Google, through the Laravel AI SDK with the company's own key) and returns a proposal
 * for a person to check: nothing is saved or posted here. Accounts the
 * company's chart lacks are dropped, and a supplier is only suggested when
 * it matches one already in the register. The audit log records that an
 * analysis was made, never the document or its content.
 */
class AnalyzeDocument
{
    private const array IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Seconds to wait for the provider. */
    private const int TIMEOUT = 120;

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
            $answer['supplier_org_number'] = $this->orgNumber($answer);
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

    /** Whether an outlay sent with only the receipt is read by NONOX AI. */
    /**
     * Employees' receipts are sent to the AI provider only when the company
     * has chosen it explicitly in the AI settings (GDPR: the employees' data
     * leaves NONOX, possibly outside the EU), not merely by turning AI on.
     */
    public static function readsReceiptsFor(Company $company): bool
    {
        $modules = app(ModuleManager::class);

        return $modules->isEnabled(AiModule::KEY, $company)
            && (bool) ($modules->settings(AiModule::KEY, $company)['read_employee_receipts'] ?? false);
    }

    /**
     * Reads the receipt of an outlay an employee sent. The employee needs no
     * AI permission of their own: the company turned NONOX AI on, and the
     * answer is only a proposal for the person who approves the outlay. A
     * cost account outside the chart, or outside 4000-7999, is dropped.
     *
     * @return array<string, mixed>
     */
    public function expenseReceipt(User $user, Company $company, string $path, string $mime): array
    {
        $answer = $this->ask($user, $company, new ExpenseReceiptAgent, $path, $mime, 'expense_receipt', 'self:expenses');

        return CurrentCompany::run($company, function () use ($answer): array {
            $account = (int) ($answer['account'] ?? 0);
            $answer['account'] = $account >= 4000 && $account <= 7999 && in_array($account, $this->accountNumbers(), true) ? $account : null;

            return $answer;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(User $user, Company $company, SupplierInvoiceAgent|VoucherAgent|ExpenseReceiptAgent $agent, string $path, string $mime, string $kind, string $permission = 'ai:use'): array
    {
        $this->modules->ensureEnabled(AiModule::KEY, $company);

        if (! $user->hasTeamPermission($company, $permission)) {
            throw new AuthorizationException;
        }

        [$provider, $choice, $custom, $key] = self::providerFor($company);

        $file = match (true) {
            $mime === 'application/pdf' => Document::fromPath($path),
            in_array($mime, self::IMAGE_TYPES, true) => Image::fromPath($path, $mime),
            HeicImage::is($mime) => Image::fromBase64(HeicImage::toJpegBase64($path), 'image/jpeg'),
            default => throw new AiAnalysisException(__('Filen går inte att analysera. Använd PDF eller en bild i JPEG, PNG, WebP eller HEIC.')),
        };

        $built = Ai::build(['name' => "nonox_ai_company_{$company->id}", 'driver' => $provider->driver(), 'key' => $key]);
        $model = $built instanceof TextProvider ? self::modelFor($built, $choice, $custom) : $custom;

        try {
            $response = $agent->prompt($this->context($company), attachments: [$file], provider: $built->name(), model: $model, timeout: self::TIMEOUT);
        } catch (Throwable $exception) {
            throw new AiAnalysisException(self::message($provider, $exception), previous: $exception);
        }

        Audit::record('ai.document_analyzed', null, [
            'kind' => $kind,
            'provider' => $provider->value,
            'model' => $model,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ], $company, $user);

        return (array) $response->toArray();
    }

    /**
     * The company's provider, model choice and decrypted key.
     *
     * @return array{AiProvider, string, ?string, string} provider, model choice, custom model, key
     */
    public static function providerFor(Company $company): array
    {
        $settings = app(ModuleManager::class)->settings(AiModule::KEY, $company);
        $provider = AiProvider::tryFrom((string) ($settings['provider'] ?? ''));
        $key = $provider === null ? null : (((array) ($settings['api_keys'] ?? []))[$provider->value] ?? null);

        if ($provider === null || ! is_string($key) || $key === '') {
            throw new AiAnalysisException(__('NONOX AI saknar leverantör eller API-nyckel. Välj leverantör och lägg in nyckeln under Företagsinställningar → Moduler → NONOX AI.'));
        }

        $choice = in_array($settings['model_choice'] ?? null, AiModule::MODEL_CHOICES, true) ? (string) $settings['model_choice'] : 'CHEAPEST';
        $custom = filled($settings['model'] ?? null) ? (string) $settings['model'] : null;

        return [$provider, $choice === 'CUSTOM' && $custom === null ? 'CHEAPEST' : $choice, $custom, Crypt::decryptString($key)];
    }

    /**
     * The model a choice means at a provider: the SDK keeps which of each
     * provider's models is cheapest and smartest up to date.
     */
    public static function modelFor(TextProvider $provider, string $choice, ?string $custom): string
    {
        return match ($choice) {
            'SMARTEST' => $provider->smartestTextModel(),
            'CUSTOM' => (string) $custom,
            default => $provider->cheapestTextModel(),
        };
    }

    /** What went wrong, said in Swedish. */
    private static function message(AiProvider $provider, Throwable $exception): string
    {
        $status = null;

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RequestException) {
                $status = $cause->response->status();
            }
        }

        return match (true) {
            $exception instanceof RateLimitedException => __(':provider tar inte emot fler analyser just nu. Försök igen om en stund.', ['provider' => $provider->label()]),
            $exception instanceof InsufficientCreditsException => __('Kontot hos :provider saknar pengar. Fyll på under fakturering hos :provider.', ['provider' => $provider->label()]),
            $exception instanceof ProviderOverloadedException => __(':provider är överbelastat just nu. Försök igen om en stund.', ['provider' => $provider->label()]),
            $exception instanceof ProviderConnectionException => __('Det gick inte att nå :provider. Kontrollera internetanslutningen och försök igen.', ['provider' => $provider->label()]),
            in_array($status, [401, 403], true) => __(':provider godtog inte API-nyckeln. Lägg in en ny under Moduler → NONOX AI.', ['provider' => $provider->label()]),
            $status === 404 => __('Modellen finns inte hos :provider. Välj den billigaste modellen under Moduler → NONOX AI.', ['provider' => $provider->label()]),
            default => __('Analysen misslyckades hos :provider. Fyll i uppgifterna själv eller försök igen.', ['provider' => $provider->label()]),
        };
    }

    /** The company's chart of accounts, for the agent to choose from. */
    private function context(Company $company): string
    {
        $chart = CurrentCompany::run($company, fn () => Account::query()->where('is_active', true)->orderBy('number')->get(['number', 'name']));
        $context = "Chart of accounts (number name):\n".$chart->map(fn (Account $account): string => "{$account->number} {$account->name}")->implode("\n");

        try {
            $priceBase = (int) ((array) CurrentCompany::run($company, fn () => RuleSet::for(CarbonImmutable::today())->get('prisbasbelopp')))['ore'];
            $context .= "\n\nHalf a price base amount (limit for förbrukningsinventarier): ".intdiv($priceBase, 200).' SEK';
        } catch (MissingPayrollRuleException) {
            // Without a checked price base amount the agent goes by the rule alone.
        }

        return $context;
    }

    /** @return list<int> */
    private function accountNumbers(): array
    {
        return array_values(Account::query()->where('is_active', true)->pluck('number')->map(fn (mixed $number): int => (int) $number)->all());
    }

    /**
     * The supplier's organisation number, or the one inside a Swedish VAT
     * number (SE + organisation number + 01) when only that is printed.
     *
     * @param  array<string, mixed>  $answer
     */
    private function orgNumber(array $answer): string
    {
        $printed = trim((string) ($answer['supplier_org_number'] ?? ''));

        if ($printed !== '') {
            return $printed;
        }

        $vat = strtoupper((string) preg_replace('/\s/', '', (string) ($answer['supplier_vat_number'] ?? '')));

        return preg_match('/^SE(\d{6})(\d{4})01$/', $vat, $parts) === 1 ? "{$parts[1]}-{$parts[2]}" : '';
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

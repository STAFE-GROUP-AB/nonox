<?php

declare(strict_types=1);

namespace App\Domain\Waitlist;

use App\Domain\Company\Industry;
use App\Models\Company;
use App\Models\WaitlistEntry;
use App\Modules\Contracts\OfferedTo;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;

/**
 * What a person who was let in from the waiting list should do first,
 * worked out from what they answered there (ADR-038 addendum): where they
 * come from, whether they have employees and what the company does. The
 * welcome mail, the checklist on the overview and the help mail all read
 * the plan, so they cannot disagree.
 */
final readonly class OnboardingPlan
{
    public const string FORTNOX = 'fortnox';

    public const string OTHER_PROGRAM = 'other';

    public const string NO_PROGRAM = 'none';

    /**
     * Where each module is explained in the manual: a section or an article
     * under /manual.
     *
     * @var array<string, string>
     */
    public const array MANUAL = [
        'inventory' => 'lager',
        'pos' => 'lager/kassa-onslip',
        'kitchen' => 'kok',
        'work_orders' => 'fakturering/arbetsorder',
        'portal' => 'fakturering/kundportal',
        'crm' => 'crm',
        'inbox' => 'leverantorer/inkorgen',
        'payroll' => 'lon',
        'staff_ledger' => 'personalliggare',
        'planning' => 'planering',
        'ai' => 'installningar/nonox-ai',
    ];

    /**
     * Further articles for a module with more than one: label => path under /manual.
     *
     * @var array<string, array<string, string>>
     */
    public const array MANUAL_EXTRA = [
        'pos' => ['Zettle' => 'lager/kassa-zettle'],
    ];

    public function __construct(
        public string $system,
        public bool $hasEmployees,
        public ?Industry $industry,
    ) {}

    public static function forEntry(WaitlistEntry $entry): self
    {
        return new self(
            self::systemFor($entry->current_system),
            $entry->employees !== 'inga',
            self::industryFor($entry->industry),
        );
    }

    /** "Inget" and "Kalkylblad eller papper" are no program; "fortnox" has its own move; anything else is another program. */
    public static function systemFor(string $currentSystem): string
    {
        return match ($currentSystem) {
            self::FORTNOX => self::FORTNOX,
            WaitlistOptions::NONE, WaitlistOptions::SPREADSHEET => self::NO_PROGRAM,
            default => self::OTHER_PROGRAM,
        };
    }

    /** The industry of an industry page's slug on the waiting list form, or null for "Annat" and anything unknown. */
    public static function industryFor(string $slug): ?Industry
    {
        foreach (Industry::cases() as $industry) {
            if ($industry->landingSlug() === $slug) {
                return $industry;
            }
        }

        return null;
    }

    /**
     * The keys suggested for an industry, in the order they are shown.
     *
     * @return list<string>
     */
    public static function moduleKeysFor(?Industry $industry, bool $hasEmployees = true): array
    {
        return array_map(fn (array $suggestion): string => $suggestion[0], self::suggestions($industry, $hasEmployees));
    }

    /**
     * The suggestions per industry, each with its reason, in the order the
     * owner decided. A module's dependencies are not reordered here; the
     * module settings tell what has to be on first. The customer portal is
     * not live and is never suggested. Payroll and planning are left out when
     * there are no employees; the staff ledger stays for the industries
     * where it is required, since the owner can be covered too.
     *
     * TODO(legal-verify): the staff ledger text "gäller även ägaren i vissa
     * fall", and that NONOX's staff ledger does not cover construction sites,
     * so crafts get none.
     *
     * @return list<array{0: string, 1: string}> key and reason
     */
    private static function suggestions(?Industry $industry, bool $hasEmployees): array
    {
        $ledger = fn (string $industryText): array => ['staff_ledger', $industryText.' '.__('Personalen checkar in och ut på en surfplatta eller med mobilen, även utan internet.')];
        $payroll = ['payroll', __('Löner, lönebesked och arbetsgivardeklaration. På Min sida har de anställda sina lönebesked och kan sjukanmäla sig och ansöka om ledighet i mobilen.')];
        $payrollMileage = ['payroll', __('Löner, lönebesked och arbetsgivardeklaration, med körjournal för resorna. På Min sida har de anställda sina lönebesked och kan sjukanmäla sig och ansöka om ledighet i mobilen.')];
        $planning = ['planning', __('Schemat med OB och lönekostnad medan du planerar, och tiden går direkt vidare till lönen.')];
        $pos = ['pos', __('Hämtar försäljningen från kassasystemet till bokföringen.')];
        $inbox = ['inbox', __('Leverantörsfakturor och kvitton mejlas in och blir förslag som du godkänner.')];

        $list = match ($industry) {
            Industry::Cafe, Industry::Restaurant => [
                $ledger(__('Krav i branschen – gäller även ägaren i vissa fall.')),
                $payroll,
                $planning,
                $pos,
                ['kitchen', __('Recept med portionskostnad, svinn och personalmat.')],
                ['inventory', __('Lagersaldo och inleverans, så du ser vad som finns och vad det kostar.')],
                $inbox,
            ],
            Industry::HairBeauty => [
                $ledger(__('Krav i branschen för frisörer och skönhetsvård – gäller även ägaren i vissa fall.')),
                $payroll,
                $planning,
                $pos,
                ['inventory', __('Lager för produkter du säljer vidare.')],
                $inbox,
            ],
            Industry::Crafts => [
                ['work_orders', __('ÄTA, ROT och RUT, tid och material direkt på plats.')],
                $payrollMileage,
                $planning,
                $inbox,
                ['ai', __('Fotografera kvitton så fyller NONOX AI i förslaget åt dig.')],
            ],
            Industry::Retail => [
                $pos,
                ['inventory', __('Artiklar, saldo och inventering.')],
                $payroll,
                $planning,
                $inbox,
            ],
            Industry::Consultant => [
                ['crm', __('Kunder, aktiviteter och nästa steg samlat på ett ställe.')],
                $inbox,
                ['ai', __('Läser fakturor och kvitton och fyller i ett förslag som du kontrollerar.')],
                $payrollMileage,
            ],
            Industry::AccountingFirm, Industry::FinanceConsultant => [
                $inbox,
                ['crm', __('Dina klienter och prospekt med nästa steg.')],
            ],
            Industry::Other, null => [
                $inbox,
                ['ai', __('Läser fakturor och kvitton och fyller i ett förslag som du kontrollerar.')],
                $payroll,
            ],
        };

        return array_values(array_filter($list, fn (array $suggestion): bool => $hasEmployees || ! in_array($suggestion[0], ['payroll', 'planning'], true)));
    }

    /** An accounting firm or a finance consultant works in Mina företag, which is core and not a module. */
    public function isFirm(): bool
    {
        return in_array($this->industry, [Industry::AccountingFirm, Industry::FinanceConsultant], true);
    }

    /**
     * The suggested modules that exist and are offered. A module that is
     * only for some companies is left out when there is no company to ask
     * about; one that is already on is left out when a company is given and
     * `$includeEnabled` is false.
     *
     * @return list<array{key: string, name: string, reason: string, manual: string, extra: list<array{name: string, url: string}>, enabled: bool}>
     */
    public function modules(?Company $company = null, bool $includeEnabled = true): array
    {
        $registry = app(ModuleRegistry::class);
        $modules = [];

        foreach (self::suggestions($this->industry, $this->hasEmployees) as [$key, $reason]) {
            if (! $registry->has($key)) {
                continue;
            }

            $module = $registry->get($key);

            if ($module instanceof OfferedTo && ($company === null || ! $module->isOfferedTo($company))) {
                continue;
            }

            $enabled = $company !== null && app(ModuleManager::class)->isEnabled($key, $company);

            if ($enabled && ! $includeEnabled) {
                continue;
            }

            $extra = [];

            foreach ($this->extraFor($key) as $label => $path) {
                $extra[] = ['name' => $label, 'url' => url('/manual/'.$path)];
            }

            $modules[] = ['key' => $key, 'name' => $module->name(), 'reason' => $reason, 'manual' => self::manualUrl($key), 'extra' => $extra, 'enabled' => $enabled];
        }

        return $modules;
    }

    /**
     * Further articles for a module: label => path under /manual. Payroll
     * points to Min sida for employees, and to the mileage log where the
     * industry drives for work.
     *
     * @return array<string, string>
     */
    private function extraFor(string $key): array
    {
        $extra = self::MANUAL_EXTRA[$key] ?? [];

        if ($key === 'payroll') {
            $extra[__('Min sida')] = 'lon/min-sida-for-anstallda';

            if (in_array($this->industry, [Industry::Crafts, Industry::Consultant], true)) {
                $extra[__('Körjournal')] = 'lon/korjournal';
            }
        }

        return $extra;
    }

    /** "ett kafé", "en restaurang": what the modules suit, in a sentence, or null when the industry is not known. */
    public function phrase(): ?string
    {
        $phrase = match ($this->industry) {
            Industry::Cafe => __('ett kafé'),
            Industry::Restaurant => __('en restaurang'),
            Industry::Crafts => __('ett hantverks- eller byggföretag'),
            Industry::Consultant => __('en konsult'),
            Industry::Retail => __('en butik eller e-handel'),
            Industry::HairBeauty => __('en frisör- eller skönhetssalong'),
            Industry::AccountingFirm => __('en redovisningsbyrå'),
            Industry::FinanceConsultant => __('en ekonomikonsult'),
            Industry::Other, null => null,
        };

        return $phrase === null ? null : (string) $phrase;
    }

    /** Where replies go: the instance's own setting, else its from address. */
    public static function replyTo(): string
    {
        return (string) (config('nonox.waitlist.reply_to') ?: config('mail.from.address'));
    }

    /** Who signs the mails: the instance's own setting, else the app's name. */
    public static function signature(): string
    {
        return (string) (config('nonox.waitlist.signature') ?: config('app.name'));
    }

    public static function manualUrl(string $key): string
    {
        return url('/manual/'.(self::MANUAL[$key] ?? ''));
    }
}

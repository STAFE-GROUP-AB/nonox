<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\SigningPdfs;
use App\Models\SigningRequest;
use App\Modules\Crm\Actions\CreateQuoteFromTemplate;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\Actions\SaveQuoteTemplate;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\QuoteTemplate;
use App\Support\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CRM stage 3 in the consultancy (ADR-046), for the manual's pages on
 * signing, the agreement register and quote templates, after CrmExtras has
 * turned CRM on: two quote templates from the article register, agreements
 * at the customers (one signed through the signing flow, one renewing soon,
 * one terminated) and a prospect's agreement out for signing, a quote made
 * from a template and signed with a drawn signature (it became an order),
 * and an open quote out for signing.
 *
 * Every name and address is made up; e-mail addresses end in .test.
 */
final class CrmSigningExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        CurrentCompany::run($c->company, function () use ($c): void {
            $templates = $this->templates($c);
            $this->agreements($c);
            $this->quotes($c, $templates);
        });
    }

    /** @return list<QuoteTemplate> */
    private function templates(DemoContext $c): array
    {
        $save = app(SaveQuoteTemplate::class);

        return [
            $save($c->user, $c->company, [
                'name' => 'Konsultuppdrag, 40 timmar',
                'intro_text' => 'Tack för att vi fick lämna offert på uppdraget. Vi börjar med en uppstartsworkshop och arbetar sedan i veckovisa avstämningar.',
                'terms_text' => 'Betalning 30 dagar netto. Resor och logi faktureras enligt kvitto. Offerten gäller i 30 dagar.',
                'validity_days' => 30,
                'rows' => [
                    ['article' => 'KONS', 'description' => 'Konsulttjänster', 'quantity' => '40'],
                    ['description' => 'Uppstartsworkshop, halvdag', 'quantity' => '1', 'unit' => 'st', 'price' => '6 500', 'vat_rate' => 2500],
                ],
            ]),
            $save($c->user, $c->company, [
                'name' => 'Arkitekturgranskning',
                'intro_text' => 'Här är vårt förslag på en granskning av er systemarkitektur.',
                'terms_text' => 'Betalning 30 dagar netto.',
                'validity_days' => 14,
                'rows' => [
                    ['article' => 'KONS', 'description' => 'Granskning och rapport', 'quantity' => '16'],
                ],
            ]),
        ];
    }

    private function agreements(DemoContext $c): void
    {
        $save = app(SaveAgreement::class);
        $today = $c->today;
        $customer = Account::query()->where('name', 'Kunden Exempel AB')->first();
        $software = Account::query()->where('name', 'Mjukvaruhuset Exempel AB')->first();
        $prospect = Account::query()->where('name', 'Fastighetsbolaget Exempel AB')->first();

        if ($customer instanceof Account) {
            $support = $this->guard($c, fn (): Agreement => $save($c->user, $c->company, [
                'account_id' => $customer->id,
                'title' => 'Supportavtal 2026',
                'kind' => 'SUPPORT',
                'start_date' => $today->subMonthsNoOverflow(10)->startOfMonth()->toDateString(),
                'end_date' => $today->addMonthsNoOverflow(2)->endOfMonth()->toDateString(),
                'notice_months' => 1,
                'auto_renew' => true,
                'renewal_months' => 12,
                'value' => '12 000',
                'value_period' => 'MONTH',
                'owner_id' => $c->user->id,
                'body_text' => "Leverantören ger support på kundens system vardagar 8–17 med svar inom fyra timmar.\nAvtalet förlängs med tolv månader i taget om det inte sägs upp senast en månad före periodens slut.",
            ]));

            if ($support instanceof Agreement) {
                $this->sign($c, $support, 'karin.exempelsson@kunden-exempel.test', 'Karin Exempelsson', 'VD');
            }

            $this->guard($c, fn (): Agreement => $save($c->user, $c->company, [
                'account_id' => $customer->id,
                'title' => 'Ramavtal konsulttjänster',
                'kind' => 'FRAMEWORK',
                'start_date' => $today->subYears(2)->startOfYear()->toDateString(),
                'end_date' => $today->addYear()->endOfYear()->toDateString(),
                'notice_months' => 3,
                'auto_renew' => false,
                'value' => '640 000',
                'value_period' => 'YEAR',
                'owner_id' => $c->user->id,
            ]));
        }

        if ($software instanceof Account) {
            $hosting = $this->guard($c, fn (): Agreement => $save($c->user, $c->company, [
                'account_id' => $software->id,
                'title' => 'Driftavtal testmiljö',
                'kind' => 'SERVICE',
                'start_date' => $today->subYear()->startOfMonth()->toDateString(),
                'end_date' => $today->addDays(40)->toDateString(),
                'notice_months' => 0,
                'auto_renew' => true,
                'renewal_months' => 6,
                'value' => '4 500',
                'value_period' => 'MONTH',
                'owner_id' => $c->user->id,
            ]));

            if ($hosting instanceof Agreement) {
                $save->terminate($c->user, $c->company, $hosting, $today->subDays(3)->toDateString());
            }
        }

        if ($prospect instanceof Account) {
            $draft = $this->guard($c, fn (): Agreement => $save($c->user, $c->company, [
                'account_id' => $prospect->id,
                'title' => 'Förvaltningsstöd fastighetssystem',
                'kind' => 'SERVICE',
                'start_date' => $today->addMonth()->startOfMonth()->toDateString(),
                'end_date' => $today->addMonth()->startOfMonth()->addYear()->subDay()->toDateString(),
                'notice_months' => 3,
                'auto_renew' => true,
                'renewal_months' => 12,
                'value' => '8 000',
                'value_period' => 'MONTH',
                'owner_id' => $c->user->id,
                'body_text' => 'Stöd i förvaltningssystemet två dagar i månaden, med uppföljning varje kvartal.',
            ]));

            if ($draft instanceof Agreement) {
                $this->guard($c, fn (): array => app(SendForSigning::class)($c->user, $c->company, $draft, ['email' => 'lena.lund@fastighetsbolaget-exempel.test']));
            }
        }
    }

    /** @param  list<QuoteTemplate>  $templates */
    private function quotes(DemoContext $c, array $templates): void
    {
        $software = Account::query()->where('name', 'Mjukvaruhuset Exempel AB')->first();
        $customer = Account::query()->where('name', 'Kunden Exempel AB')->first();
        $create = app(CreateQuoteFromTemplate::class);

        // A signed quote, three days ago: it became an order.
        if ($software instanceof Account && $software->customer_id !== null && isset($templates[1])) {
            $this->at(3, fn () => $this->guard($c, function () use ($c, $create, $templates, $software): void {
                $quote = $create($c->user, $c->company, $templates[1], (int) $software->customer_id);
                $deal = Deal::query()->where('account_id', $software->id)->where('status', DealStatus::Open->value)->first();
                $sent = app(SendForSigning::class)($c->user, $c->company, $quote, ['email' => 'ali.nord@mjukvaruhuset-exempel.test', 'deal_id' => $deal?->id]);
                $this->signRequest($sent, 'Ali Nord', 'Teknisk chef', drawn: true);
            }));
        }

        // An open quote out for signing (the public page's example).
        if ($customer instanceof Account && $customer->customer_id !== null && isset($templates[0])) {
            $this->guard($c, function () use ($c, $create, $templates, $customer): void {
                $quote = $create($c->user, $c->company, $templates[0], (int) $customer->customer_id);
                app(SendForSigning::class)($c->user, $c->company, $quote, ['email' => 'karin.exempelsson@kunden-exempel.test']);
            });
        }
    }

    private function sign(DemoContext $c, Agreement $agreement, string $email, string $name, string $title): void
    {
        $this->guard($c, function () use ($c, $agreement, $email, $name, $title): void {
            $sent = app(SendForSigning::class)($c->user, $c->company, $agreement, ['email' => $email]);
            $this->signRequest($sent, $name, $title, drawn: false);
        });
    }

    /** @param  array{request: SigningRequest, url: string}  $sent */
    private function signRequest(array $sent, string $name, string $title, bool $drawn): void
    {
        $links = app(SignAtLink::class);
        $request = $links->find(basename((string) parse_url($sent['url'], PHP_URL_PATH))) ?? $sent['request'];

        $links->sign($request, [
            'name' => $name,
            'title' => $title,
            'accept' => '1',
            'method' => $drawn ? SigningRequest::DRAWN : SigningRequest::TYPED,
            'signature' => $drawn ? self::drawnSignature() : null,
            'typed_signature' => $drawn ? null : $name,
            'document_sha256' => $request->document_sha256,
            ...SigningPdfs::acceptanceFields($request),
        ], '192.0.2.10', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Exempel');
    }

    /** A made-up hand-drawn line, as the signature pad would send it. */
    private static function drawnSignature(): string
    {
        $image = imagecreatetruecolor(600, 200);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        $ink = (int) imagecolorallocate($image, 17, 24, 39);
        imagesetthickness($image, 4);
        $points = [[40, 140], [80, 60], [110, 150], [150, 70], [190, 140], [240, 90], [300, 120], [360, 80], [420, 130], [520, 100]];

        for ($i = 1; $i < count($points); $i++) {
            imageline($image, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $ink);
        }

        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * Runs $step and reports a refusal as a warning instead of stopping the seeding.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T|null
     */
    private function guard(DemoContext $c, callable $step): mixed
    {
        try {
            return $step();
        } catch (ValidationException $exception) {
            $c->warn(collect($exception->errors())->flatten()->implode(' '));
        } catch (Throwable $exception) {
            $c->warn($exception->getMessage());
        }

        return null;
    }

    /** @param  callable(): void  $step */
    private function at(int $daysAgo, callable $step): void
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow(CarbonImmutable::now('Europe/Stockholm')->subDays($daysAgo)->setTime(14, 20));

        try {
            $step();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}

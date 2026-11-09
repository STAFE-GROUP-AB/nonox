<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Landing\LandingPage;
use App\Support\Status\SecurityPolicy;
use App\Support\Status\SecurityTxt;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The legal documents of the hosted service: privacy policy, terms, data
 * processing agreement and sub-processors. The texts live in docs/juridik
 * as Markdown, so they are versioned and reviewed like the rest of the
 * documentation; placeholders such as {foretag} are filled in from
 * config('app.landing').
 */
class LegalPageController
{
    public const string VERSION = '1.7';

    public const string EFFECTIVE = '2027-01-18';

    /** Route name => file in docs/juridik and page title. */
    public const array DOCUMENTS = [
        'privacy' => ['file' => 'integritetspolicy', 'title' => 'Integritetspolicy'],
        'terms' => ['file' => 'villkor', 'title' => 'Allmänna villkor'],
        'dpa' => ['file' => 'personuppgiftsbitradesavtal', 'title' => 'Personuppgiftsbiträdesavtal'],
        'subprocessors' => ['file' => 'underbitraden', 'title' => 'Underbiträden'],
        'selfHosting' => ['file' => 'egen-drift', 'title' => 'NONOX i egen drift'],
        'accessibility' => ['file' => 'tillganglighet', 'title' => 'Tillgänglighetsredogörelse'],
        'securityPolicy' => ['file' => 'sakerhetspolicy', 'title' => 'Säkerhetspolicy'],
    ];

    public function __invoke(string $document): View
    {
        abort_if($document === 'securityPolicy' && ! SecurityPolicy::published(), 404);

        $meta = self::DOCUMENTS[$document];

        return view('start.legal', [
            ...LandingPage::data(),
            'html' => self::html($document),
            'pageTitle' => "{$meta['title']} – NONOX",
            'pageDescription' => "{$meta['title']} för tjänsten NONOX.",
        ]);
    }

    public static function html(string $document): string
    {
        $markdown = (string) file_get_contents(base_path('docs/juridik/'.self::DOCUMENTS[$document]['file'].'.md'));
        $markdown = $document === 'securityPolicy' ? SecurityPolicy::filter($markdown) : $markdown;
        $markdown = (string) preg_replace('/<!--.*?-->/s', '', $markdown);

        return Str::markdown(strtr($markdown, self::placeholders()), ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /** The contact shown in the security policy: the one in security.txt, without mailto:. */
    private static function securityContact(): string
    {
        $contact = SecurityTxt::contact();

        return $contact === null ? 'kontaktuppgifterna på sidan Säkerhet och regelverk' : (string) preg_replace('/^mailto:/', '', $contact);
    }

    /** @return array<string, string> */
    public static function placeholders(): array
    {
        $company = (string) (config('app.landing.company_name') ?: 'Stafe Development AB');
        $orgnr = (string) config('app.landing.org_number');
        $email = (string) config('app.landing.privacy_email');
        $address = (string) config('app.landing.company_address');

        return [
            '{foretag}' => $company,
            '{orgnr}' => $orgnr !== '' ? "org.nr {$orgnr}" : 'del av Stafe Group AB',
            '{epost}' => $email !== '' ? $email : 'kontaktuppgifterna på webbplatsen',
            '{adress}' => $address !== '' ? $address : '',
            '{webbplats}' => url('/'),
            '{version}' => self::VERSION,
            '{datum}' => self::EFFECTIVE,
            '{session}' => (string) config('session.lifetime'),
            '{pub}' => route('legal.dpa'),
            '{integritet}' => route('legal.privacy'),
            '{underbitraden}' => route('legal.subprocessors'),
            '{cookies}' => route('cookies'),
            '{villkor}' => route('legal.terms'),
            '{egendrift}' => route('legal.selfHosting'),
            '{sakerhet}' => route('security'),
            '{sakerhetskontakt}' => self::securityContact(),
            '{sakerhetstxt}' => SecurityTxt::baseUrl().'/.well-known/security.txt',
            '{status}' => SecurityTxt::baseUrl().route('status', [], false),
            '{tillganglighet}' => route('legal.accessibility'),
        ];
    }
}

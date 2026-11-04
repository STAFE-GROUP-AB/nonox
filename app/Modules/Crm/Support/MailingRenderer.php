<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Models\Company;
use App\Modules\Crm\Domain\MergeFields;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\MailingLink;
use App\Support\Branding\Brand;
use Illuminate\Support\Str;

/**
 * Turns a mailing's Markdown into the message each recipient gets
 * (ADR-048): merge fields filled in, Markdown to HTML with raw HTML
 * stripped and unsafe links dropped (league/commonmark through Laravel's
 * Str::markdown), every http(s) link replaced by the recipient's own
 * redirect address, and the company's colour, name, postal address,
 * organisation number and the unsubscribe link in the frame.
 *
 * No image is loaded from anywhere and nothing is measured on opening:
 * the only thing counted is a click on a link (owner's decision).
 */
final class MailingRenderer
{
    /**
     * The links of a mailing's body, in order, once each.
     *
     * @return list<string>
     */
    public static function links(string $markdown): array
    {
        preg_match_all('/<a\s[^>]*href="(https?:\/\/[^"]+)"/i', self::markdown($markdown), $matches);

        return array_values(array_unique(array_map(fn (string $url): string => html_entity_decode($url, ENT_QUOTES | ENT_HTML5), $matches[1])));
    }

    /**
     * @param  array<string, string>  $values  merge field values
     * @param  ?callable(string): ?string  $track  the redirect address for an original URL, or null to keep it
     */
    public static function html(Mailing $mailing, Company $company, array $values, ?string $unsubscribeUrl, ?callable $track = null): string
    {
        $body = self::markdown(MergeFields::render($mailing->body, array_map(self::escapeMarkdown(...), $values)));

        if ($track !== null) {
            $body = (string) preg_replace_callback('/(<a\s[^>]*href=")(https?:\/\/[^"]+)(")/i', function (array $match) use ($track): string {
                $tracked = $track(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5));

                return $match[1].e($tracked ?? html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5)).$match[3];
            }, $body);
        }

        return view('crm::mail.mailing', [
            'body' => $body,
            'company' => $company,
            'brand' => Brand::for($company),
            'subject' => MergeFields::render($mailing->subject, $values),
            'unsubscribeUrl' => $unsubscribeUrl,
            'footer' => self::footer($company),
        ])->render();
    }

    /** @param  array<string, string>  $values */
    public static function text(Mailing $mailing, Company $company, array $values, ?string $unsubscribeUrl): string
    {
        $lines = [
            trim(MergeFields::render($mailing->body, $values)),
            '',
            '--',
            implode("\n", self::footer($company)),
        ];

        if ($unsubscribeUrl !== null) {
            $lines[] = __('Avregistrera dig från utskicken: :url', ['url' => $unsubscribeUrl]);
        }

        return implode("\n", $lines);
    }

    /**
     * The sender's identity under every mailing (marknadsföringslagen 20 §, e-handelslagen).
     *
     * @return list<string>
     */
    public static function footer(Company $company): array
    {
        return array_values(array_filter([
            collect([$company->legal_name ?: $company->name, $company->org_number ? __('org.nr :number', ['number' => $company->org_number]) : null])->filter()->implode(', '),
            collect([$company->address_line1, trim(($company->postal_code ?? '').' '.($company->city ?? ''))])->filter()->implode(', '),
            $company->email,
        ]));
    }

    /** Whether the company has the postal address every mailing must carry. */
    public static function hasPostalAddress(Company $company): bool
    {
        return filled($company->address_line1) && filled($company->postal_code) && filled($company->city);
    }

    /**
     * @param  iterable<MailingLink>  $links
     * @return array<string, MailingLink> URL => link
     */
    public static function linkMap(iterable $links): array
    {
        $map = [];

        foreach ($links as $link) {
            $map[$link->url] ??= $link;
        }

        return $map;
    }

    private static function markdown(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /** A merge value is text, never Markdown: a name cannot become a link. */
    private static function escapeMarkdown(string $value): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!<>|~])/', '\\\\$1', $value);
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Developer;

/**
 * The guides of the developer site, written in docs/api/guider/{sv,en}/
 * as Markdown so they read the same on GitHub. Their order here is the
 * order in the menu; the slug is the Swedish path under /utvecklare.
 */
final class Guides
{
    /** @var array<string, array{sv: array{0: string, 1: string}, en: array{0: string, 1: string}}> slug => language => [menu title, description] */
    private const array GUIDES = [
        'kom-igang' => [
            'sv' => ['Kom igång', 'Från noll till en bokförd verifikation via API:t på fem minuter: modul, nyckel och de första anropen.'],
            'en' => ['Getting started', 'From nothing to a posted voucher through the API in five minutes: module, key and the first calls.'],
        ],
        'autentisering' => [
            'sv' => ['Autentisering (OAuth)', 'OAuth 2.0 med auktoriseringskod och PKCE steg för steg, med riktiga anrop och svar, godkännande med passkey och förnyade tokens.'],
            'en' => ['Authentication (OAuth)', 'OAuth 2.0 authorization code with PKCE step by step, with real requests and responses, passkey consent and token rotation.'],
        ],
        'api-nycklar' => [
            'sv' => ['API-nycklar', 'Nycklar för egna skript och integrationer: hur de skapas, vad de får göra och hur de återkallas.'],
            'en' => ['API keys', "Keys for a company's own scripts and integrations: how they are created, what they may do and how they are revoked."],
        ],
        'behorigheter' => [
            'sv' => ['Behörigheter', 'Varje scope, vilka anrop det öppnar och hur det samverkar med personens egen roll i företaget.'],
            'en' => ['Scopes', "Every scope, which calls it opens and how it combines with the person's own role in the company."],
        ],
        'idempotens' => [
            'sv' => ['Idempotens', 'Idempotency-Key på varje POST, så att ett nytt försök efter ett nätverksfel aldrig bokför två gånger.'],
            'en' => ['Idempotency', 'An Idempotency-Key on every POST, so a retry after a network error never books anything twice.'],
        ],
        'sidindelning' => [
            'sv' => ['Sidindelning och synk', 'Listor med markör, och hur en synk hämtar bara det som ändrats med updated_since.'],
            'en' => ['Pagination and sync', 'Cursor-paginated lists, and how a sync fetches only what changed with updated_since.'],
        ],
        'fel' => [
            'sv' => ['Fel', 'Alla fel som problem+json (RFC 9457): fälten, varje kod och vad programmet ska göra.'],
            'en' => ['Errors', 'Every error as problem+json (RFC 9457): the fields, every code and what a program should do.'],
        ],
        'granser' => [
            'sv' => ['Gränser för anrop', 'Hur många anrop som går per minut, svarshuvudena som visar det och hur man väntar rätt.'],
            'en' => ['Rate limits', 'How many calls per minute are allowed, the headers that show it and how to back off.'],
        ],
        'webhooks' => [
            'sv' => ['Webhooks', 'Händelser som skickas till din server när något bokförs, och hur signaturen kontrolleras i PHP, JavaScript och Python.'],
            'en' => ['Webhooks', 'Events sent to your server when something is booked, and how to verify the signature in PHP, JavaScript and Python.'],
        ],
        'sftp' => [
            'sv' => ['SFTP för SIE-filer', 'Lämna SIE-filer i företagets SFTP-mapp med en SSH-nyckel: anslutning, mappar, gränser och vad som händer med filen.'],
            'en' => ['SFTP for SIE files', "Deliver SIE files to the company's SFTP drop with an SSH key: connecting, folders, limits and what happens to the file."],
        ],
        'egen-drift' => [
            'sv' => ['Egen drift', 'API:t på en NONOX som du driftar själv: adresser, nycklar för signering, appar och gränser.'],
            'en' => ['Self-hosting', 'The API on a NONOX you run yourself: addresses, signing keys, apps and limits.'],
        ],
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::GUIDES);
    }

    public static function exists(string $slug): bool
    {
        return isset(self::GUIDES[$slug]);
    }

    public static function title(string $slug, string $lang): string
    {
        return self::GUIDES[$slug][self::lang($lang)][0];
    }

    public static function description(string $slug, string $lang): string
    {
        return self::GUIDES[$slug][self::lang($lang)][1];
    }

    public static function file(string $slug, string $lang): string
    {
        return base_path('docs/api/guider/'.self::lang($lang)."/{$slug}.md");
    }

    private static function lang(string $lang): string
    {
        return $lang === 'en' ? 'en' : 'sv';
    }
}

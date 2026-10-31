<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use App\Models\Company;
use App\Modules\Inbox\Models\InboxMailbox;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Str;

/**
 * The receiving addresses (ADR-023):
 *
 * - {slug}@in.nonox.app per company, with the aliases +kvitto, +faktura
 *   and +kassa as a hint of what is sent. The slug is the company name
 *   with a random suffix, so it cannot be guessed, and the owner can
 *   replace it.
 * - mejl@in.nonox.app, shared by every company, where the sender's address
 *   decides the company through the companies' allowlists.
 */
final class InboxAddress
{
    public const array ALIASES = ['kvitto', 'faktura', 'kassa'];

    public static function domain(): string
    {
        return mb_strtolower((string) config('services.inbox.domain', 'in.nonox.app'));
    }

    public static function sharedLocalPart(): string
    {
        return mb_strtolower((string) config('services.inbox.shared_local', 'mejl'));
    }

    public static function shared(): string
    {
        return self::sharedLocalPart().'@'.self::domain();
    }

    public static function forSlug(string $slug, ?string $alias = null): string
    {
        return $slug.($alias === null ? '' : "+{$alias}").'@'.self::domain();
    }

    /**
     * The company's mailbox, created with a new slug the first time.
     */
    public static function mailbox(Company $company): InboxMailbox
    {
        return CurrentCompany::run($company, fn (): InboxMailbox => InboxMailbox::query()->first()
            ?? InboxMailbox::create(['slug' => self::newSlug($company)]));
    }

    /**
     * The company name in lower-case ASCII with a random suffix of eight
     * characters (about 41 bits), unique across every company.
     */
    public static function newSlug(Company $company): string
    {
        $base = Str::of(Str::ascii($company->name))->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->limit(40, '')->trim('-')->toString();
        $base = $base === '' ? 'foretag' : $base;

        do {
            $slug = $base.'-'.Str::lower(Str::random(8));
            $slug = (string) preg_replace('/[^a-z0-9-]/', 'x', $slug);
            // The slug is unique across companies, so the check looks at every company.
            $taken = CompanyScope::bypass(fn (): bool => InboxMailbox::query()->where('slug', $slug)->exists());
        } while ($taken || $slug === self::sharedLocalPart());

        return $slug;
    }

    /**
     * Splits a recipient on the inbox domain into its local part and alias:
     * "acme-x1y2+kassa@in.nonox.app" => ["acme-x1y2", "kassa"]. Null when
     * the address is on another domain.
     *
     * @return ?array{string, ?string}
     */
    public static function parse(string $recipient): ?array
    {
        $recipient = mb_strtolower(trim($recipient, " <>\t"));

        if (preg_match('/^([a-z0-9-]{1,80})(?:\+([a-z0-9-]{1,20}))?@(.+)$/', $recipient, $match) !== 1 || $match[3] !== self::domain()) {
            return null;
        }

        $alias = $match[2] === '' ? null : $match[2];

        return [$match[1], in_array($alias, self::ALIASES, true) ? $alias : null];
    }
}

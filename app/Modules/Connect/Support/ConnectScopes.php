<?php

declare(strict_types=1);

namespace App\Modules\Connect\Support;

/**
 * The scopes an app or API key can be given (ADR-032). Every scope is a
 * permission key from AGENTS.md §4, so a token can never do more than its
 * scopes, and never more than the person who granted it may do right now:
 * User::hasTeamPermission() checks both on every request.
 *
 * Only permissions that an endpoint uses are offered. A scope is added here
 * together with the endpoints that need it.
 */
final class ConnectScopes
{
    public const array SCOPES = [
        'vouchers:view',
        'vouchers:create',
        'vouchers:post',
        'vouchers:correct',
        'accounts:manage',
        'reports:view',
        'vat:prepare',
        'export:sie',
        'invoices:view',
        'invoices:create',
        'invoices:send',
        'invoices:credit',
        'payments:create',
        'supplier-invoices:create',
        'supplier-invoices:approve',
        'planning:view',
    ];

    /** @return array<string, string> scope => Swedish description, for the consent screen and API keys */
    public static function labels(): array
    {
        return [
            'vouchers:view' => __('Läsa verifikationer, underlag, kontoplan, räkenskapsår, perioder, kostnadsställen, projekt, verifikationsserier och momskoder'),
            'vouchers:create' => __('Skapa och ändra utkast till verifikationer och lägga till underlag'),
            'vouchers:post' => __('Bokföra verifikationer (det går inte att ångra, bara rätta)'),
            'vouchers:correct' => __('Rätta bokförda verifikationer med en rättelseverifikation'),
            'accounts:manage' => __('Lägga till och ändra kostnadsställen och projekt'),
            'reports:view' => __('Läsa rapporter: saldobalans, huvudbok, balansrapport och resultatrapport'),
            'vat:prepare' => __('Läsa momsdeklarationen per period'),
            'export:sie' => __('Hämta bokföringen som SIE-fil'),
            'invoices:view' => __('Läsa kunder, artiklar, kundfakturor och inbetalningar'),
            'invoices:create' => __('Lägga till och ändra kunder, artiklar och utkast till kundfakturor'),
            'invoices:send' => __('Skapa kundfakturor av utkast (bokförs, går inte att ändra efteråt) och mejla dem till kunden'),
            'invoices:credit' => __('Kreditera kundfakturor'),
            'payments:create' => __('Registrera inbetalningar på kundfakturor och betalningar av attesterade leverantörsfakturor'),
            'supplier-invoices:create' => __('Läsa leverantörer och leverantörsfakturor, lägga till leverantörer och registrera leverantörsfakturor'),
            'supplier-invoices:approve' => __('Läsa och attestera leverantörsfakturor (aldrig sådana som personen själv har registrerat, om företaget kräver attest av någon annan)'),
            'planning:view' => __('Läsa schemat i Planering: pass per period, avdelningar och publiceringar'),
        ];
    }

    public static function label(string $scope): string
    {
        return self::labels()[$scope] ?? $scope;
    }

    /**
     * The valid, known scopes among the given ones, in the canonical order.
     *
     * @param  array<array-key, mixed>  $scopes
     * @return list<string>
     */
    public static function filter(array $scopes): array
    {
        return array_values(array_intersect(self::SCOPES, array_map('strval', $scopes)));
    }
}

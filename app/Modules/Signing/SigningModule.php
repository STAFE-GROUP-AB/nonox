<?php

declare(strict_types=1);

namespace App\Modules\Signing;

use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\User;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\ModuleCheck;
use App\Modules\Signing\Models\SigningDocument;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Signering (ADR-046 amendment 2026-11-18, gap 10, package S7; ADR-017): a
 * company's own PDFs sent for signing, with or without CRM. The engine
 * (envelopes, several signers, checks, the event chain, reminders) is core
 * and works for quotes, agreements and ÄTA whether or not this module is on
 * (ADR-075 §7); the module adds the standalone documents (type DOCUMENT),
 * the list of every envelope, the envelope page and the send wizard, and
 * confidential envelopes. Requires nothing. Off by default. Turning it off
 * hides the screens and closes the open links of standalone documents;
 * signed documents stay readable with signing:view, kept as an archive.
 *
 * Permissions: signing:view sees the envelope list, the files and the
 * certificates; signing:send uploads, sends, reminds, moves the last day
 * and withdraws one's own envelopes; signing:manage does the same for
 * everyone's envelopes and sees confidential ones. The approver gets
 * nothing, and the field worker keeps only the on-site flow (ADR-074).
 * The core permissions signing:settings (retention), signing:hold (legal
 * hold, also the accountant) and signing:privacy (a signer's register extract and erasure) belong to the
 * engine (ADR-046 gap 11, package S16) and live in CompanyRole.
 */
final class SigningModule implements IsNew, Module
{
    public const string KEY = 'signing';

    public const string VIEW = 'signing:view';

    public const string SEND = 'signing:send';

    public const string MANAGE = 'signing:manage';

    public const array PERMISSIONS = [self::VIEW, self::SEND, self::MANAGE];

    public function newUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-06-01');
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Signering');
    }

    public function description(): string
    {
        return __('Skicka egna PDF-dokument för signering med flera undertecknare i vald ordning, kontroll av undertecknaren, påminnelser och en lista över alla signeringar. Fungerar med eller utan CRM.');
    }

    public function icon(): string
    {
        return 'pen-line';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return self::PERMISSIONS;
    }

    public function roles(): array
    {
        return [];
    }

    /** ADR-046 gap 10: the approver gets nothing; the sales role (CRM) sends and sees. */
    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
            'accountant' => self::PERMISSIONS,
            'bookkeeper' => [self::VIEW, self::SEND],
            'auditor' => [self::VIEW],
            'sales' => [self::VIEW, self::SEND],
        ];
    }

    /** Signed standalone documents, their certificates and evidence stay readable while the module is off. */
    public function archivePermissions(): array
    {
        return [self::VIEW];
    }

    public function archiveNavigation(Company $company): array
    {
        $kept = CurrentCompany::run($company, fn (): bool => SigningEnvelope::query()->where('document_type', SigningDocument::SIGNING_TYPE)->where('status', 'COMPLETED')->exists());

        if (! $kept) {
            return [];
        }

        return [
            [
                'label' => __('Signeringsarkiv'),
                'route' => 'signing.envelopes',
                'permission' => self::VIEW,
                'active' => 'signing.envelope*',
            ],
        ];
    }

    public function navigation(): array
    {
        return [
            [
                'label' => __('Signering'),
                'route' => 'signing.envelopes',
                'permission' => self::VIEW,
                'active' => ['signing.envelopes', 'signing.envelope', 'signing.new'],
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Alla signeringar'), 'route' => 'signing.envelopes', 'active' => ['signing.envelopes', 'signing.envelope']],
                    ...($user->hasTeamPermission($company, self::SEND) ? [
                        ['label' => __('Skicka dokument'), 'route' => 'signing.new', 'active' => ['signing.new']],
                        ['label' => __('Mallar'), 'route' => 'signing.templates', 'active' => ['signing.templates', 'signing.template', 'signing.template.new']],
                    ] : []),
                ],
            ],
        ];
    }

    public function settingsComponent(): ?string
    {
        return null;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        $open = CurrentCompany::run($company, fn (): int => SigningEnvelope::query()->where('document_type', SigningDocument::SIGNING_TYPE)->where('status', 'OUT')->count());

        return new ModuleCheck(warnings: [
            ...($open > 0 ? [trans_choice('{1} Ett dokument är ute för signering. Dess länkar slutar fungera medan modulen är avstängd.|[2,*] :count dokument är ute för signering. Deras länkar slutar fungera medan modulen är avstängd.', $open, ['count' => $open])] : []),
            __('Signerade dokument och signeringsbevis går fortfarande att läsa med behörigheten att se signeringar. Signering av offerter, avtal och ÄTA påverkas inte.'),
        ]);
    }

    public function onEnable(Company $company, User $by): void {}

    public function onDisable(Company $company, User $by): void {}
}

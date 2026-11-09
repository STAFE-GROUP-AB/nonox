<?php

declare(strict_types=1);

namespace App\Modules\Boardroom;

use App\Domain\Company\LegalForm;
use App\Domain\Deadlines\Contracts\DeadlineProvider;
use App\Domain\Overview\Contracts\TodoProvider;
use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\Domain\EventHash;
use App\Modules\Boardroom\Models\Meeting;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Modules\Boardroom\Support\BoardroomDeadlines;
use App\Modules\Boardroom\Support\BoardroomTodos;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\Contracts\OfferedTo;
use App\Modules\Contracts\ProvidesDeadlines;
use App\Modules\Contracts\ProvidesTodos;
use App\Modules\ModuleCheck;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Aktiebok och styrelserum (ADR-070): the share register as an append-only
 * event log with a hash chain, the articles of association as versioned
 * data, and the meetings with agenda, notice advice and minutes approved in
 * NONOX. Offered only to aktiebolag. It never posts a voucher and never
 * files anything with Bolagsverket.
 */
final class BoardroomModule implements IsNew, Module, OfferedTo, ProvidesDeadlines, ProvidesTodos
{
    public const string KEY = 'boardroom';

    public const array PERMISSIONS = [
        'share-register:view',
        'share-register:manage',
        'meetings:view',
        'meetings:manage',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Aktiebok och styrelserum');
    }

    public function description(): string
    {
        return __('Aktieboken med full historik som inte kan ändras i efterhand, bolagsordningen, och stämmor och styrelsemöten med dagordning, kallelse och protokoll. Bara för aktiebolag.');
    }

    public function icon(): string
    {
        return 'book';
    }

    public function newUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-03-01');
    }

    public function isOfferedTo(Company $company): bool
    {
        return $company->legal_form === LegalForm::Ab;
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

    /**
     * ADR-070 §1 and owner decision 2: owner, admin and the accountant
     * (Redovisningskonsult, who may keep the register for a client) get all
     * four; the auditor reads. A firm user records register entries only
     * once the company has recorded the board's authorisation (BoardroomAccess).
     */
    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
            'accountant' => self::PERMISSIONS,
            'auditor' => ['share-register:view', 'meetings:view'],
        ];
    }

    /** The register is kept as long as the company exists (ABL 5 kap. 3 §): it stays readable with the module off. */
    public function archivePermissions(): array
    {
        return ['share-register:view', 'meetings:view'];
    }

    public function archiveNavigation(Company $company): array
    {
        // One query whatever the history: is there a register entry or a meeting at all?
        $has = CurrentCompany::run($company, fn (): bool => ShareEvent::query()->select('id')->limit(1)
            ->union(Meeting::query()->select('id')->limit(1))
            ->exists());

        if (! $has) {
            return [];
        }

        return [
            ['label' => __('Aktiebok (arkiv)'), 'route' => 'share-register.index', 'permission' => 'share-register:view', 'active' => ['share-register.index', 'share-register.events']],
            ['label' => __('Styrelserum (arkiv)'), 'route' => 'boardroom.meetings', 'permission' => 'meetings:view', 'active' => ['boardroom.meetings', 'boardroom.meetings.show']],
        ];
    }

    public function navigation(): array
    {
        return [
            ['label' => __('Aktiebok'), 'route' => 'share-register.index', 'permission' => 'share-register:view', 'active' => 'share-register.*', 'children' => fn (User $user, Company $company): array => [
                ['label' => __('Aktieboken'), 'route' => 'share-register.index', 'active' => ['share-register.index']],
                ['label' => __('Händelser'), 'route' => 'share-register.events', 'active' => ['share-register.events']],
                ['label' => __('Aktieägare'), 'route' => 'share-register.shareholders', 'active' => ['share-register.shareholders']],
                ['label' => __('Bolagsordning'), 'route' => 'share-register.articles', 'active' => ['share-register.articles']],
            ]],
            ['label' => __('Styrelserum'), 'route' => 'boardroom.meetings', 'permission' => 'meetings:view', 'active' => 'boardroom.*'],
        ];
    }

    public function settingsComponent(): ?string
    {
        return null;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        $check = ModuleCheck::allow();

        if (! $this->isOfferedTo($company)) {
            $check = $check->withBlocker(__('Aktiebok och styrelserum finns bara för aktiebolag.'));
        }

        if (! EventHash::hasKey()) {
            $check = $check->withBlocker(__('Nyckeln till aktiebokens hashkedja saknas på servern. Den som driftar NONOX kör php artisan share-register:hash-key --write en gång.'));
        }

        return $check;
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Aktieboken och protokollen finns kvar och kan läsas och skrivas ut. Styrelsen ansvarar ändå för att aktieboken förs (aktiebolagslagen 5 kap. 7 §).'),
        ]);
    }

    public function onEnable(Company $company, User $by): void {}

    public function onDisable(Company $company, User $by): void {}

    public function todoProvider(): TodoProvider
    {
        return app(BoardroomTodos::class);
    }

    public function deadlineProvider(): DeadlineProvider
    {
        return app(BoardroomDeadlines::class);
    }
}

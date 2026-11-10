<?php

declare(strict_types=1);

namespace App\Domain\Waitlist;

use App\Domain\Company\Industry;
use App\Migration\Fortnox\FortnoxConnection;
use App\Models\Company;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;

/**
 * The steps that are added to the Kom igång checklist on the overview for
 * someone who came through the waiting list or whose company states an
 * industry (ADR-038 addendum). They follow the same plan as the welcome mail
 * (OnboardingPlan): a Fortnox user gets "Flytta från Fortnox" first, a company
 * with employees gets a payroll step, and a known industry gets a step that
 * names the modules worth switching on.
 *
 * @phpstan-type Step array{done: bool, title: string, text: string, link: ?string, allowed?: bool, soon: bool}
 */
final class PersonalChecklist
{
    /**
     * @return array{fortnox: ?Step, payroll: ?Step, modules: ?Step}
     */
    public static function for(User $user, Company $company): array
    {
        $entry = WaitlistEntry::approvedFor($user);

        // The entry's answers are about the person's first company: one they own and created after the approval.
        if ($entry !== null && ($company->user_id !== $user->id || $entry->decided_at === null || $company->created_at < $entry->decided_at)) {
            $entry = null;
        }
        $plan = new OnboardingPlan(
            $entry === null ? OnboardingPlan::NO_PROGRAM : OnboardingPlan::systemFor($entry->current_system),
            $entry !== null && $entry->employees !== 'inga',
            $company->industry ?? ($entry === null ? null : OnboardingPlan::forEntry($entry)->industry),
        );

        return [
            'fortnox' => $entry !== null && $plan->system === OnboardingPlan::FORTNOX ? self::fortnox($user, $company) : null,
            'payroll' => $plan->hasEmployees ? self::payroll($user, $company) : null,
            'modules' => $plan->industry === null ? null : self::modules($user, $company, $plan, $plan->industry),
        ];
    }

    /** @return Step */
    private static function fortnox(User $user, Company $company): array
    {
        return [
            'done' => FortnoxConnection::query()->exists(),
            'title' => __('Flytta från Fortnox'),
            'text' => __('Logga in på ditt Fortnox-konto härifrån, så hämtar NONOX bokföringen, registren och underlagen åt dig, år för år.'),
            'link' => route('fortnox.move'),
            'allowed' => $user->hasTeamPermission($company, 'import:fortnox'),
            'soon' => false,
        ];
    }

    /** @return Step */
    private static function payroll(User $user, Company $company): array
    {
        $enabled = app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company);

        if (! $enabled) {
            return [
                'done' => false,
                'title' => __('Lägg upp lön och anställda'),
                'text' => __('Ni har anställda. Slå på modulen Lön, så kan du lägga upp dem och köra lön. Har ni löner i ett annat program finns hjälp att flytta lönedata.'),
                'link' => route('teams.show', ['company' => $company->id, 'flik' => 'moduler']),
                'allowed' => $user->hasTeamPermission($company, 'modules:manage'),
                'soon' => false,
            ];
        }

        return [
            'done' => Employee::query()->exists(),
            'title' => __('Lägg upp lön och anställda'),
            'text' => __('Lägg upp de anställda och kör lönen i NONOX. Har ni löner i ett annat program kan du flytta lönedata dit.'),
            'link' => route('payroll.employees'),
            'allowed' => $user->hasTeamPermission($company, 'employees:manage'),
            'soon' => false,
        ];
    }

    /** @return ?Step */
    private static function modules(User $user, Company $company, OnboardingPlan $plan, Industry $industry): ?array
    {
        $suggested = $plan->modules($company);

        if ($suggested === []) {
            return null;
        }

        $remaining = array_values(array_filter($suggested, fn (array $module): bool => ! $module['enabled']));
        $industryPhrase = $plan->phrase() ?? __('ditt företag');
        $names = implode(', ', array_column($remaining === [] ? $suggested : $remaining, 'name'));

        return [
            'done' => $remaining === [],
            'title' => __('Slå på modulerna för din bransch'),
            'text' => $remaining === []
                ? __('Modulerna som brukar passa :industry är påslagna: :modules.', ['industry' => $industryPhrase, 'modules' => $names])
                : __('Det här brukar passa :industry: :modules. Slå på de du behöver under Moduler.', ['industry' => $industryPhrase, 'modules' => $names]),
            'link' => route('teams.show', ['company' => $company->id, 'flik' => 'moduler']),
            'allowed' => $user->hasTeamPermission($company, 'modules:manage'),
            'soon' => false,
        ];
    }
}

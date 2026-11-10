<?php

declare(strict_types=1);

use App\Domain\Company\Industry;
use App\Domain\Waitlist\OnboardingPlan;
use App\Mail\OnboardingReminderMail;
use App\Mail\WaitlistApprovedMail;
use App\Modules\Contracts\OfferedTo;
use App\Modules\ModuleRegistry;
use Tests\Support\Bookkeeping;
use Tests\Support\Onboarding;

/*
 * The module suggestions per industry, each with a reason (ADR-038 addendum).
 */

function suggestedKeys(Industry $industry, bool $employees): array
{
    return array_column((new OnboardingPlan(OnboardingPlan::NO_PROGRAM, $employees, $industry))->modules(), 'key');
}

it('tells a café with employees about the staff ledger, payroll and planning first, each with a reason', function () {
    $modules = (new OnboardingPlan(OnboardingPlan::NO_PROGRAM, true, Industry::Cafe))->modules();

    expect(array_column($modules, 'key'))->toBe(['staff_ledger', 'payroll', 'planning', 'pos', 'kitchen', 'inventory', 'inbox'])
        ->and(array_filter(array_column($modules, 'reason'), fn (string $reason): bool => trim($reason) === ''))->toBe([]);
});

it('suggests the same for a restaurant, and the hair and beauty list in its own order', function () {
    expect(suggestedKeys(Industry::Restaurant, true))->toBe(suggestedKeys(Industry::Cafe, true))
        ->and(suggestedKeys(Industry::HairBeauty, true))->toBe(['staff_ledger', 'payroll', 'planning', 'pos', 'inventory', 'inbox']);
});

it('keeps the staff ledger for the industries that need it even without employees, and drops payroll and planning', function () {
    foreach ([Industry::Cafe, Industry::Restaurant, Industry::HairBeauty] as $industry) {
        $keys = suggestedKeys($industry, false);

        expect($keys)->toContain('staff_ledger')->not->toContain('payroll')->not->toContain('planning');
    }

    $ledger = collect((new OnboardingPlan(OnboardingPlan::NO_PROGRAM, false, Industry::Cafe))->modules())->firstWhere('key', 'staff_ledger');
    expect($ledger['reason'])->toContain('Krav i branschen')->toContain('ägaren');
});

it('never suggests the staff ledger to crafts, and never the customer portal to anyone', function () {
    expect(suggestedKeys(Industry::Crafts, true))->toBe(['work_orders', 'payroll', 'planning', 'inbox', 'ai']);

    foreach (Industry::cases() as $industry) {
        foreach ([true, false] as $employees) {
            expect(suggestedKeys($industry, $employees))->not->toContain('portal');
        }
    }

    foreach ([Industry::Crafts, Industry::Retail, Industry::Consultant, Industry::AccountingFirm, Industry::FinanceConsultant, Industry::Other] as $industry) {
        expect(suggestedKeys($industry, true))->not->toContain('staff_ledger');
    }
});

it('suggests the other lists as decided', function () {
    expect(suggestedKeys(Industry::Retail, true))->toBe(['pos', 'inventory', 'payroll', 'planning', 'inbox'])
        ->and(suggestedKeys(Industry::Consultant, true))->toBe(['crm', 'inbox', 'ai', 'payroll'])
        ->and(suggestedKeys(Industry::AccountingFirm, true))->toBe(['inbox', 'crm'])
        ->and(suggestedKeys(Industry::FinanceConsultant, false))->toBe(['inbox', 'crm'])
        ->and(suggestedKeys(Industry::Other, true))->toBe(['inbox', 'ai', 'payroll'])
        ->and(suggestedKeys(Industry::Other, false))->toBe(['inbox', 'ai']);
});

it('leaves out payroll and planning when there are no employees', function () {
    foreach (Industry::cases() as $industry) {
        expect(suggestedKeys($industry, false))->not->toContain('payroll')->not->toContain('planning');
    }
});

it('only suggests modules that exist and are offered to the company', function () {
    [, $company] = Bookkeeping::company();
    $registry = app(ModuleRegistry::class);

    foreach (Industry::cases() as $industry) {
        foreach ([true, false] as $employees) {
            $plan = new OnboardingPlan(OnboardingPlan::NO_PROGRAM, $employees, $industry);

            foreach (OnboardingPlan::moduleKeysFor($industry, $employees) as $key) {
                expect($registry->has($key))->toBeTrue("{$industry->value}: {$key}");
            }

            foreach ($plan->modules($company) as $module) {
                $instance = $registry->get($module['key']);
                expect($instance instanceof OfferedTo ? $instance->isOfferedTo($company) : true)->toBeTrue();
            }

            expect(count($plan->modules($company)))->toBe(count(OnboardingPlan::moduleKeysFor($industry, $employees)));
        }
    }
});

it('links every suggestion to a manual section or article that exists', function () {
    foreach (Industry::cases() as $industry) {
        foreach (new OnboardingPlan(OnboardingPlan::NO_PROGRAM, true, $industry)->modules() as $module) {
            foreach ([$module['manual'], ...array_column($module['extra'], 'url')] as $url) {
                $path = base_path('docs/manual/sv/'.ltrim(str_replace(url('/manual'), '', $url), '/'));

                expect(is_dir($path) || is_file($path.'.md'))->toBeTrue("{$industry->value}: {$url}");
            }
        }
    }

    foreach (['byra/mina-foretag', 'lon/min-sida-for-anstallda', 'lon/korjournal', 'installningar/nonox-ai'] as $article) {
        expect(is_file(base_path("docs/manual/sv/{$article}.md")))->toBeTrue($article);
    }
});

it('names the reasons, Min sida and the till articles in the café mail', function () {
    [$entry] = Onboarding::approved(['industry' => 'bokforing-for-cafeer', 'employees' => '5-9']);
    $html = (new WaitlistApprovedMail($entry))->render();

    expect($html)->toContain(url('/manual/personalliggare'))->toContain(url('/manual/planering'))->toContain(url('/manual/lon/min-sida-for-anstallda'))
        ->toContain(url('/manual/lager/kassa-onslip'))->toContain(url('/manual/kok'))
        ->toContain('Krav i branschen');
});

it('points an accounting firm to Mina företag, which is core and not a module', function () {
    [$entry] = Onboarding::approved(['industry' => 'for-redovisningsbyraer']);

    expect((new WaitlistApprovedMail($entry))->render())->toContain('Mina företag')->toContain(url('/manual/byra/mina-foretag'));
});

it('puts room between the list items, inlined on every li so mail clients keep it', function () {
    [$entry] = Onboarding::approved(['industry' => 'bokforing-for-cafeer', 'employees' => '5-9']);

    foreach ([new WaitlistApprovedMail($entry), new OnboardingReminderMail($entry, 'help')] as $mail) {
        preg_match_all('~<li[^>]*>~', $mail->render(), $items);

        expect($items[0])->not->toBe([]);

        foreach ($items[0] as $tag) {
            expect($tag)->toContain('margin-bottom: 10px');
        }
    }
});

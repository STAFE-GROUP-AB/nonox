<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\VerifyPayrollRule;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\PayrollRuleVerification;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every rule a payroll run needs, on a chosen date: its value, source and who
 * verified it. A run is blocked until every rule is verified.
 */
class PayrollRules extends Component
{
    use ShowsToasts;

    #[Locked]
    public ?int $checking = null;

    public bool $confirmed = false;

    #[Url(as: 'datum', except: '')]
    public string $date = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        /** @var Company $company */
        $company = $user->currentCompany();
        abort_unless($user->hasTeamPermission($company, 'payroll:view'), 403);
    }

    public function check(int $ruleId): void
    {
        $this->checking = $ruleId;
        $this->confirmed = false;
        $this->resetErrorBag();
    }

    public function verify(VerifyPayrollRule $verify): void
    {
        if (! $this->confirmed) {
            $this->addError('confirmed', __('Bekräfta att du har jämfört värdet med källan.'));

            return;
        }

        /** @var User $user */
        $user = Auth::user();
        /** @var Company $company */
        $company = $user->currentCompany();
        $rule = PayrollRuleVersion::query()->findOrFail($this->checking);
        $verify($user, $company, $rule);

        $this->reset('checking', 'confirmed');
        $this->toast(__('Regeln :key är kontrollerad av dig för :company.', ['key' => $rule->key, 'company' => $company->name]));
    }

    public function render(): View
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1 ? CarbonImmutable::parse($this->date) : CarbonImmutable::today();
        $versions = PayrollRuleVersion::query()
            ->whereIn('key', RuleSet::REQUIRED_KEYS)
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date->toDateString()))
            ->orderBy('valid_from')
            ->get()
            ->groupBy('key');

        /** @var User $user */
        $user = Auth::user();
        /** @var Company $company */
        $company = $user->currentCompany();

        return view('payroll::livewire.rules', [
            'companyChecks' => PayrollRuleVerification::query()->get()->keyBy('payroll_rule_version_id'),
            'canVerify' => $user->hasTeamPermission($company, 'payroll:settings'),
            'day' => $date,
            'rows' => array_map(fn (string $key): array => [
                'key' => $key,
                'versions' => $versions->get($key, collect())->values(),
            ], RuleSet::REQUIRED_KEYS),
            'missing' => RuleSet::for($date)->missing(),
        ]);
    }
}

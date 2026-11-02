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
use App\Modules\Payroll\Support\YearReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every rule a payroll run needs: first whether a whole payment year is
 * ready (rules and Skatteverket's tax tables), then the rules on a chosen
 * date with value, source and who verified them. A run is blocked until
 * every rule it uses is verified.
 */
class PayrollRules extends Component
{
    use ShowsToasts;

    #[Locked]
    public ?int $checking = null;

    /** Which list the check was opened from: "year" or "day". */
    #[Locked]
    public string $checkingIn = 'day';

    public bool $confirmed = false;

    #[Url(as: 'datum', except: '')]
    public string $date = '';

    #[Url(as: 'ar', except: '')]
    public string $year = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'payroll:view'), 403);
    }

    public function check(int $ruleId, string $in = 'day'): void
    {
        $this->checking = $ruleId;
        $this->checkingIn = $in === 'year' ? 'year' : 'day';
        $this->confirmed = false;
        $this->resetErrorBag();
    }

    public function cancelCheck(): void
    {
        $this->reset('checking', 'confirmed');
    }

    public function verify(VerifyPayrollRule $verify): void
    {
        if (! $this->confirmed) {
            $this->addError('confirmed', __('Bekräfta att du har jämfört värdet med källan.'));

            return;
        }

        $company = $this->company();
        $rule = PayrollRuleVersion::query()->findOrFail($this->checking);
        $verify($this->user(), $company, $rule);

        $this->reset('checking', 'confirmed');
        $this->toast(__('Regeln :key är kontrollerad av dig för :company.', ['key' => $rule->key, 'company' => $company->name]));
    }

    public function render(): View
    {
        $today = CarbonImmutable::today();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1 ? CarbonImmutable::parse($this->date) : $today;
        $years = [$today->year, $today->year + 1];
        $year = in_array((int) $this->year, $years, true) ? (int) $this->year : YearReadiness::defaultYear($today);
        $versions = PayrollRuleVersion::query()
            ->whereIn('key', RuleSet::REQUIRED_KEYS)
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date->toDateString()))
            ->orderBy('valid_from')
            ->get()
            ->groupBy('key');

        return view('payroll::livewire.rules', [
            'companyChecks' => PayrollRuleVerification::query()->get()->keyBy('payroll_rule_version_id'),
            'canVerify' => $this->user()->hasTeamPermission($this->company(), 'payroll:settings'),
            'day' => $date,
            'rows' => array_map(fn (string $key): array => [
                'key' => $key,
                'versions' => $versions->get($key, collect())->values(),
            ], RuleSet::REQUIRED_KEYS),
            'missing' => RuleSet::for($date)->missing(),
            'readiness' => YearReadiness::for($year),
            'years' => $years,
        ]);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\AuditLog;
use App\Modules\Saas\Actions\CloseSaasMonth;
use App\Modules\Saas\Domain\Months;
use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\SaasSettings;
use App\Modules\Saas\Support\SaasLedger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Återkommande intäkter → Månader (screen 7): every ledger month, open or
 * closed, who closed it and when, the nightly verification, and "Stäng
 * månad" for the first month that has ended (O6: a person closes, months
 * close in order). Four queries.
 */
class MonthsPage extends Component
{
    use InSaas;
    use ShowsToasts;

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function close(string $month, CloseSaasMonth $close): void
    {
        try {
            $closed = $close($this->user(), $this->company(), $month);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        } catch (AuthorizationException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->toast(__(':month är stängd. Dess siffror ändras inte längre; det som blir känt senare visas som rättelse.', ['month' => ucfirst(Months::label($closed->key()))]));
    }

    public function render(): View
    {
        $company = $this->company();

        return view('saas::livewire.months', [
            'months' => SaasMonth::query()->with('closer:id,name')->orderByDesc('month')->get(),
            'closable' => SaasLedger::closable(CarbonImmutable::today()),
            'canManage' => $this->canManage(),
            'verified' => AuditLog::query()->where('company_id', $company->id)->where('action', 'saas.verified')->latest('id')->first(),
            'historyFrom' => SaasSettings::historyFrom($company),
            'reminderDay' => SaasSettings::closeReminderDay($company),
        ]);
    }
}

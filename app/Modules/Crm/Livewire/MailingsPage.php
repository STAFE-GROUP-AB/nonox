<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Modules\Crm\Actions\SaveMailing;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Support\CrmMailer;
use App\Modules\Crm\Support\MailingPause;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Utskick (ADR-048): the company's mailings with their status and counts,
 * newest first. "Nytt utskick" makes a draft and opens it. crm:mailings.
 */
class MailingsPage extends Component
{
    use ActsInCompany;
    use ShowsToasts;
    use WithPagination;

    public function mount(): void
    {
        $this->authorizeMailings();
    }

    public function create(SaveMailing $save): void
    {
        $this->authorizeMailings();

        $mailing = $save($this->user(), $this->company(), [
            'name' => __('Nytt utskick :date', ['date' => now()->setTimezone('Europe/Stockholm')->format('Y-m-d')]),
            'subject' => __('Nyheter från {mitt_företag}'),
            'body' => __("Hej {förnamn}!\n\nSkriv utskicket här. **Fetstil**, *kursiv*, listor och [länkar](https://exempel.se) fungerar.\n\nMed vänliga hälsningar\n{mitt_företag}"),
        ]);

        $this->redirectRoute('crm.mailings.show', $mailing->id, navigate: false);
    }

    /** After an automatic pause (MailingPause): the company has dealt with the cause. */
    public function resume(MailingPause $pause): void
    {
        $this->authorizeMailings();

        try {
            $pause->resume($this->user(), $this->company());
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Utskicken är återupptagna.'));
    }

    public function render(): View
    {
        return view('crm::livewire.mailings', [
            'notice' => self::notice($this->company()),
            'paused' => MailingPause::pausedAt($this->company()) !== null,
            'hqOnly' => MailingPause::hqOnly($this->company()),
            'mailings' => Mailing::query()->with('segment:id,name')->latest('id')->paginate(25),
        ]);
    }

    /** Why mailings cannot go now: the hosted CRM mailer is missing, the mailings are paused, or no privacy policy link. */
    public static function notice(Company $company): ?string
    {
        return match (true) {
            ! CrmMailer::available() => CrmMailer::unavailableMessage(),
            MailingPause::pausedAt($company) !== null => MailingPause::message($company),
            CrmSettings::privacyUrl($company) === null => (string) __('Fyll i Länk till er integritetspolicy under CRM → Inställningar innan ni skickar. Varje utskick länkar dit.'),
            default => null,
        };
    }

    private function authorizeMailings(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:mailings'), 403);
    }
}

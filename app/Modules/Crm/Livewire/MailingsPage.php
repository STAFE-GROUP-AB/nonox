<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Crm\Actions\SaveMailing;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Mailing;
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

    public function render(): View
    {
        return view('crm::livewire.mailings', [
            'mailings' => Mailing::query()->with('segment:id,name')->latest('id')->paginate(25),
        ]);
    }

    private function authorizeMailings(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:mailings'), 403);
    }
}

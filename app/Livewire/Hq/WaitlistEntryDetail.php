<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Actions\Waitlist\ApproveWaitlistEntry;
use App\Actions\Waitlist\DeleteWaitlistEntry;
use App\Actions\Waitlist\SetWaitlistEntryStatus;
use App\Domain\Waitlist\WaitlistStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Hq\Concerns\DecidesWithPasskey;
use App\Models\WaitlistEntry;
use App\Support\Hq\HqAccess;
use App\Support\Tenancy\SpansCompanies;
use App\Support\Toast;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One entry on the waiting list in /hq (ADR-038): every answer, and the
 * decisions Godkänn, Neka, Parkera and Radera, each confirmed with a passkey.
 */
class WaitlistEntryDetail extends Component implements SpansCompanies
{
    use DecidesWithPasskey;
    use ShowsToasts;

    #[Locked]
    public int $entryId;

    public string $note = '';

    public function mount(int $entryId): void
    {
        $this->entryId = $entryId;
        $this->entry();
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function approve(?array $credential, ApproveWaitlistEntry $approve): void
    {
        $this->confirmDecision($credential);

        $approve($this->admin(), $this->entry(), $this->note);

        $this->note = '';
        $this->toast(__('Anmälan är godkänd. Kontot är skapat och ett mejl om inloggningen är på väg.'));
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function reject(?array $credential, SetWaitlistEntryStatus $set): void
    {
        $this->confirmDecision($credential);

        $set($this->admin(), $this->entry(), WaitlistStatus::Rejected, $this->note);

        $this->note = '';
        $this->toast(__('Anmälan är nekad. Den raderas automatiskt efter 30 dagar.'));
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function park(?array $credential, SetWaitlistEntryStatus $set): void
    {
        $this->confirmDecision($credential);

        $set($this->admin(), $this->entry(), WaitlistStatus::Parked, $this->note);

        $this->note = '';
        $this->toast(__('Anmälan är parkerad. Du kan godkänna eller neka den senare.'));
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function delete(?array $credential, DeleteWaitlistEntry $delete): void
    {
        $this->confirmDecision($credential);

        $released = $delete($this->admin(), $this->entry());

        // A company account someone worked on stays in the CRM without the person (ADR-044): say so.
        Toast::flash(match (true) {
            $released['kept'] > 0 => __('Anmälan och alla svar är raderade, liksom personen i CRM. Företagets konto i CRM har aktiviteter eller affärer och ligger kvar utan personen.'),
            $released['erased_people'] > 0 => __('Anmälan och alla svar är raderade, liksom personen i CRM.'),
            default => __('Anmälan och alla svar är raderade.'),
        });

        $this->redirectRoute('hq.waitlist', navigate: false);
    }

    public function render(): View
    {
        $admin = $this->admin();

        return view('livewire.hq.waitlist-entry-detail', [
            'entry' => $this->entry()->load(['decider', 'approvedUser']),
            'fresh' => HqAccess::isFresh($admin),
            'hasPasskey' => $admin->hasPasskeysEnabled(),
        ]);
    }

    private function entry(): WaitlistEntry
    {
        return WaitlistEntry::query()->findOrFail($this->entryId);
    }
}

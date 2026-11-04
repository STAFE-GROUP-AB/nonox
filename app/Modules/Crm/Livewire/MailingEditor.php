<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Crm\Actions\SaveMailing;
use App\Modules\Crm\Actions\SendMailing;
use App\Modules\Crm\Domain\MailingAudience;
use App\Modules\Crm\Domain\MailingStatus;
use App\Modules\Crm\Domain\MarketingExclusion;
use App\Modules\Crm\Domain\MergeFields;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\MailingLink;
use App\Modules\Crm\Models\Segment;
use App\Modules\Crm\Support\MailingRenderer;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One mailing (ADR-048): the draft (name, segment, subject, Markdown text,
 * the customer exception), who it reaches and why the others are left
 * out, a preview, a test to yourself, and Skicka nu or Schemalägg. Once
 * started, the counts: sent, delivered, bounced, unique clicks per link,
 * unsubscribed. Never who opened or who clicked. crm:mailings.
 */
class MailingEditor extends Component
{
    use ActsInCompany;
    use ShowsToasts;

    #[Locked]
    public int $mailingId;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $scheduledAt = '';

    public bool $confirmSend = false;

    public function mount(int $mailingId): void
    {
        $this->authorizeMailings();
        $mailing = Mailing::query()->findOrFail($mailingId);
        $this->mailingId = $mailing->id;
        $this->fillForm($mailing);
    }

    public function save(SaveMailing $save): void
    {
        $this->authorizeMailings();
        $save($this->user(), $this->company(), $this->form, $this->mailing());
        $this->confirmSend = false;
        $this->toast(__('Utkastet är sparat.'));
    }

    public function sendTest(SaveMailing $save, SendMailing $send): void
    {
        $this->authorizeMailings();
        $mailing = $save($this->user(), $this->company(), $this->form, $this->mailing());
        $send->test($this->user(), $this->company(), $mailing);
        $this->toast(__('Ett testmejl är skickat till :email.', ['email' => $this->user()->email]));
    }

    public function askSend(SaveMailing $save): void
    {
        $this->authorizeMailings();
        $save($this->user(), $this->company(), $this->form, $this->mailing());
        $this->confirmSend = true;
    }

    public function send(SendMailing $send): void
    {
        $this->authorizeMailings();

        try {
            $mailing = $send->start($this->user(), $this->company(), $this->mailing(), $this->scheduledAt === '' ? null : $this->scheduledAt);
        } catch (ValidationException $exception) {
            $this->confirmSend = false;
            $this->addError('send', $this->firstError($exception));

            return;
        }

        $this->confirmSend = false;
        $this->toast($mailing->status === MailingStatus::Scheduled
            ? __('Utskicket är schemalagt till :count mottagare.', ['count' => $mailing->recipient_count])
            : __('Utskicket skickas till :count mottagare.', ['count' => $mailing->recipient_count]));
    }

    public function cancel(SendMailing $send): void
    {
        $this->authorizeMailings();
        $send->cancel($this->user(), $this->company(), $this->mailing());
        $this->toast(__('Utskicket är avbrutet. Det som redan har gått kan inte tas tillbaka.'));
    }

    public function delete(SaveMailing $save): void
    {
        $this->authorizeMailings();
        $save->delete($this->user(), $this->company(), $this->mailing());
        $this->toast(__('Utkastet är borttaget.'));
        $this->redirectRoute('crm.mailings', navigate: false);
    }

    public function render(): View
    {
        $mailing = $this->mailing();
        $draft = $mailing->status === MailingStatus::Draft;
        $audience = null;

        if ($draft) {
            $preview = $mailing->replicate();
            $preview->segment_id = blank($this->form['segment_id'] ?? null) ? null : (int) $this->form['segment_id'];
            $preview->customer_exception = (bool) ($this->form['customer_exception'] ?? false);
            $audience = MailingAudience::for($preview);
        }

        return view('crm::livewire.mailing-editor', [
            'mailing' => $mailing,
            'draft' => $draft,
            'audience' => $audience,
            'segments' => Segment::query()->orderBy('name')->get(['id', 'name']),
            'links' => $draft ? collect() : MailingLink::query()->where('mailing_id', $mailing->id)->orderBy('position')->get(),
            'exclusions' => MarketingExclusion::cases(),
            'fields' => MergeFields::help(),
            'hasAddress' => MailingRenderer::hasPostalAddress($this->company()),
            'notice' => MailingsPage::notice($this->company()),
        ]);
    }

    private function fillForm(Mailing $mailing): void
    {
        $this->form = [
            'name' => $mailing->name,
            'subject' => $mailing->subject,
            'body' => $mailing->body,
            'segment_id' => (string) ($mailing->segment_id ?? ''),
            'customer_exception' => $mailing->customer_exception,
        ];
    }

    private function mailing(): Mailing
    {
        return Mailing::query()->findOrFail($this->mailingId);
    }

    private function authorizeMailings(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:mailings'), 403);
    }
}

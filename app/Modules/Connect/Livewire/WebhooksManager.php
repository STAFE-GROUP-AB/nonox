<?php

declare(strict_types=1);

namespace App\Modules\Connect\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Actions\CreateWebhook;
use App\Modules\Connect\Actions\DeleteWebhook;
use App\Modules\Connect\Actions\RotateWebhookSecret;
use App\Modules\Connect\Actions\SetWebhookActive;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Modules\Connect\Support\PasskeyStepUp;
use App\Modules\Connect\Webhooks\WebhookEvents;
use App\Support\Auth\PasskeySession;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Webhooks under Anslutningar (ADR-032): the company's subscriptions, what
 * each one sends and how the latest deliveries went. Everyone with
 * connect:view sees them; owners and administrators add them and change
 * their secret (with a passkey), turn them off and on, and remove them.
 */
class WebhooksManager extends Component
{
    use ShowsToasts;

    public const string NAME = 'connect.webhooks-manager';

    #[Locked]
    public int $companyId;

    public string $url = '';

    public string $description = '';

    /** @var list<string> */
    public array $events = [];

    /** The new or changed secret, shown once and then forgotten. */
    #[Locked]
    public ?string $newSecret = null;

    public function mount(): void
    {
        $company = $this->company();
        abort_unless($this->user()->hasTeamPermission($company, 'connect:view'), 403);

        $this->companyId = $company->id;
    }

    /** @return array<array-key, mixed> */
    public function passkeyOptions(): array
    {
        return PasskeyStepUp::start($this->user());
    }

    /**
     * @param  ?array<array-key, mixed>  $credential  the passkey's answer; null only where passkeys are skipped locally
     */
    public function createWebhook(?array $credential, CreateWebhook $create): void
    {
        $this->confirmPasskey($credential);

        $result = $create($this->user(), $this->company(), [
            'url' => $this->url,
            'description' => $this->description,
            'events' => $this->events,
        ]);

        $this->newSecret = $result['secret'];
        $this->reset(['url', 'description', 'events']);
        $this->toast(__('Webhooken är tillagd. Kopiera hemligheten nu, den visas bara en gång.'));
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function rotateSecret(string $webhookId, ?array $credential, RotateWebhookSecret $rotate): void
    {
        $this->confirmPasskey($credential);

        $this->newSecret = $rotate($this->user(), $this->company(), $this->webhook($webhookId));
        $this->toast(__('Webhooken har fått en ny hemlighet. Den gamla slutar gälla direkt.'));
    }

    public function setActive(string $webhookId, bool $active, SetWebhookActive $set): void
    {
        $set($this->user(), $this->company(), $this->webhook($webhookId), $active);

        $this->toast($active
            ? __('Webhooken är påslagen. Nya händelser skickas från och med nu.')
            : __('Webhooken är avstängd. Inga händelser skickas förrän du slår på den igen.'));
    }

    public function deleteWebhook(string $webhookId, DeleteWebhook $delete): void
    {
        $delete($this->user(), $this->company(), $this->webhook($webhookId));

        $this->toast(__('Webhooken är borttagen.'));
    }

    public function forgetSecret(): void
    {
        $this->newSecret = null;
    }

    public function render(): View
    {
        $company = $this->company();
        $user = $this->user();

        return view('connect::livewire.webhooks-manager', CurrentCompany::run($company, fn (): array => [
            'webhooks' => ConnectWebhook::query()
                ->with(['creator', 'deliveries' => fn ($query) => $query->latest()->limit(10)])
                ->orderBy('created_at')
                ->get(),
            'eventLabels' => WebhookEvents::labels(),
            // Only the events this person may receive themselves (CreateWebhook refuses the others).
            'choosableEvents' => array_filter(WebhookEvents::labels(), fn (string $event): bool => CreateWebhook::mayReceive($user, $company, $event), ARRAY_FILTER_USE_KEY),
            'canManage' => $user->hasTeamPermission($company, 'connect:manage'),
            'hasPasskey' => $user->hasPasskeysEnabled(),
            'skipPasskey' => PasskeySession::skippedLocally(),
        ]));
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    private function confirmPasskey(?array $credential): void
    {
        if ($credential !== null) {
            PasskeyStepUp::confirm($this->user(), $credential);

            return;
        }

        if (! PasskeySession::skippedLocally()) {
            throw PasskeyStepUp::missingPasskey();
        }
    }

    private function webhook(string $id): ConnectWebhook
    {
        abort_unless(Str::isUuid($id), 404);

        return CurrentCompany::run($this->company(), fn (): ConnectWebhook => ConnectWebhook::query()->findOrFail($id));
    }

    private function company(): Company
    {
        $company = isset($this->companyId) ? Company::query()->find($this->companyId) : $this->user()->currentCompany();
        abort_unless($company instanceof Company, 404);

        return $company;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}

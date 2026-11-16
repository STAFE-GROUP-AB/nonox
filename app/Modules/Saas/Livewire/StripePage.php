<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Customer;
use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Stripe\Actions\ConnectStripe;
use App\Modules\Saas\Stripe\Actions\EndStripeConnection;
use App\Modules\Saas\Stripe\Actions\LinkStripeCustomer;
use App\Modules\Saas\Stripe\Actions\SaveStripeWebhookSecret;
use App\Modules\Saas\Stripe\Actions\StripePermissions;
use App\Modules\Saas\Stripe\Jobs\SyncStripe;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\Saas\Stripe\Models\StripeSyncRun;
use App\Support\Connect\ConnectAddons;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Återkommande intäkter → Stripe (ADR-080 §7, step 2): Stripe in NONOX
 * Connect with its price, the connection with a restricted read-only key, the
 * optional webhook, the sync log and the matching of Stripe customers to the
 * company's customers. The key and the webhook secret are never sent back to
 * the browser: the fields are emptied on every answer. A fixed number of
 * queries, whatever the number of Stripe customers (lists of at most LIMIT).
 */
class StripePage extends Component
{
    use InSaas;
    use ShowsToasts;
    use WithPagination;

    /** Stripe customers per list and page; each list pages on its own, in the query string (review P3-7). */
    public const int LIMIT = 25;

    public string $key = '';

    public string $webhookSecret = '';

    /** @var array<int|string, string> link id => the customer number typed for it */
    public array $numbers = [];

    public function mount(): void
    {
        $this->authorizeView();
    }

    /** The key and the signing secret never go back to the browser, whatever the answer (review P3-2). */
    public function dehydrate(): void
    {
        $this->key = '';
        $this->webhookSecret = '';
    }

    public function erase(int $link, LinkStripeCustomer $action): void
    {
        $this->attempt(function () use ($link, $action): string {
            $action->erase($this->user(), $this->company(), StripeCustomerLink::query()->findOrFail($link));

            return __('Stripe-kundens uppgifter är raderade i NONOX och läses inte in igen. Ta också bort kunden i Stripe om personen har begärt radering.');
        });
    }

    public function enableAddon(): void
    {
        $this->attempt(function (): string {
            StripePermissions::authorizeAddon($this->user(), $this->company());
            ConnectAddons::set($this->company(), $this->user(), ConnectAddons::STRIPE, true);

            return __('Stripe är påslaget i NONOX Connect. Du kan nu koppla ditt Stripe-konto.');
        });
    }

    public function disableAddon(): void
    {
        $this->attempt(function (): string {
            StripePermissions::authorizeAddon($this->user(), $this->company());

            if (StripeConnection::query()->where('status', StripeConnection::ACTIVE)->exists()) {
                throw ValidationException::withMessages(['addon' => __('Koppla från Stripe först.')]);
            }

            ConnectAddons::set($this->company(), $this->user(), ConnectAddons::STRIPE, false);

            return __('Stripe är avstängt i NONOX Connect.');
        });
    }

    public function connect(ConnectStripe $connect): void
    {
        $key = $this->key;
        // Never sent back to the browser, also when the key is refused.
        $this->key = '';

        $this->attempt(function () use ($connect, $key): string {
            $rotated = StripeConnection::query()->where('status', StripeConnection::ACTIVE)->exists();
            $connect($this->user(), $this->company(), $key);

            return $rotated
                ? __('Nyckeln är bytt. Den gamla nyckeln kan du nu låta gå ut i Stripe.')
                : __('Stripe är kopplat. Prenumerationer och kunder läses in nu; det kan ta några minuter.');
        });
    }

    public function disconnect(EndStripeConnection $end): void
    {
        $this->attempt(function () use ($end): string {
            $connection = StripeConnection::query()->where('status', StripeConnection::ACTIVE)->firstOrFail();
            $end($this->user(), $this->company(), $connection);

            return __('Stripe är frånkopplat och nyckeln är raderad i NONOX. Låt den också gå ut i Stripe under API-nycklar.');
        });
    }

    public function saveWebhook(SaveStripeWebhookSecret $save): void
    {
        $secret = $this->webhookSecret;
        $this->webhookSecret = '';

        $this->attempt(function () use ($save, $secret): string {
            $connection = StripeConnection::query()->where('status', StripeConnection::ACTIVE)->firstOrFail();
            $save($this->user(), $this->company(), $connection, $secret);

            return $secret === '' ? __('Signeringshemligheten är borttagen.') : __('Signeringshemligheten är sparad. Ändringar i Stripe kommer nu fram inom någon minut.');
        });
    }

    public function syncNow(): void
    {
        $this->attempt(function (): string {
            StripePermissions::authorizeManage($this->user(), $this->company());
            $connection = StripeConnection::query()->where('status', StripeConnection::ACTIVE)->firstOrFail();
            SyncStripe::dispatch($this->company()->id, $connection->id);

            return __('Inläsningen från Stripe är startad.');
        });
    }

    public function acceptSuggestion(int $link, LinkStripeCustomer $action): void
    {
        $this->attempt(function () use ($link, $action): string {
            $row = StripeCustomerLink::query()->findOrFail($link);
            $customer = Customer::query()->findOrFail((int) $row->suggested_customer_id);
            $action->link($this->user(), $this->company(), $row, $customer);

            return __('Stripe-kunden är kopplad till :customer.', ['customer' => $customer->name]);
        });
    }

    public function linkTo(int $link, LinkStripeCustomer $action): void
    {
        $this->attempt(function () use ($link, $action): string {
            $row = StripeCustomerLink::query()->findOrFail($link);
            $number = trim((string) ($this->numbers[$link] ?? ''));
            $customer = ctype_digit($number) ? Customer::query()->where('number', (int) $number)->first() : null;

            if ($customer === null) {
                throw ValidationException::withMessages(["numbers.{$link}" => __('Det finns ingen kund med kundnummer :number.', ['number' => $number])]);
            }

            $action->link($this->user(), $this->company(), $row, $customer);
            unset($this->numbers[$link]);

            return __('Stripe-kunden är kopplad till :customer.', ['customer' => $customer->name]);
        });
    }

    public function confirm(int $link, LinkStripeCustomer $action): void
    {
        $this->attempt(function () use ($link, $action): string {
            $action->confirm($this->user(), $this->company(), StripeCustomerLink::query()->findOrFail($link));

            return __('Kopplingen är granskad.');
        });
    }

    public function unlink(int $link, LinkStripeCustomer $action): void
    {
        $this->attempt(function () use ($link, $action): string {
            $action->unlink($this->user(), $this->company(), StripeCustomerLink::query()->findOrFail($link));

            return __('Kopplingen är borttagen. Stripe-kunden räknas som en egen kund tills du kopplar den.');
        });
    }

    public function createCustomer(int $link, LinkStripeCustomer $action): void
    {
        $this->attempt(function () use ($link, $action): string {
            $customer = $action->createCustomer($this->user(), $this->company(), StripeCustomerLink::query()->findOrFail($link));

            return __('Kunden :customer är skapad med kundnummer :number och kopplad.', ['customer' => $customer->name, 'number' => $customer->number]);
        });
    }

    /** @param  callable(): string  $work */
    private function attempt(callable $work): void
    {
        try {
            $this->toast($work());
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        } catch (AuthorizationException $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function render(): View
    {
        $company = $this->company();
        $available = StripePermissions::available();
        $connection = StripeConnection::query()->orderByRaw("status = 'ACTIVE' desc")->orderByDesc('id')->first();
        $counts = [];
        $lists = ['review' => null, 'suggested' => null, 'unlinked' => null];
        $canConnect = $this->canManage() && $this->user()->hasTeamPermission($company, 'settings:manage');

        if ($connection !== null) {
            $counts = StripeCustomerLink::query()
                ->where('connection_id', $connection->id)
                ->withSubscriptions()
                ->select('status', DB::raw('count(*) as count'), DB::raw('count(*) filter (where confirmed_at is null and status = \'LINKED\') as review'))
                ->groupBy('status')
                ->get()
                ->keyBy('status')
                ->map(fn ($row): array => ['count' => (int) $row->getAttribute('count'), 'review' => (int) $row->getAttribute('review')])
                ->all();

            $list = fn (string $status, string $page, bool $unconfirmed = false) => StripeCustomerLink::query()
                ->with(['customer:id,number,name', 'suggested:id,number,name'])
                ->where('connection_id', $connection->id)
                ->where('status', $status)
                ->when($unconfirmed, fn ($query) => $query->whereNull('confirmed_at'))
                ->withSubscriptions()
                ->orderBy('id')
                ->paginate(self::LIMIT, pageName: $page);
            $lists = [
                'review' => $list(StripeCustomerLink::LINKED, 'granska', true),
                'suggested' => $list(StripeCustomerLink::SUGGESTED, 'forslag'),
                'unlinked' => $list(StripeCustomerLink::UNLINKED, 'kunder'),
            ];
        }

        return view('saas::livewire.stripe', [
            'available' => $available,
            'addonOn' => ConnectAddons::isOn($company, ConnectAddons::STRIPE),
            'price' => ConnectAddons::price(ConnectAddons::STRIPE),
            'connection' => $connection,
            'runs' => $connection === null ? collect() : StripeSyncRun::query()->where('connection_id', $connection->id)->orderByDesc('id')->limit(10)->get(),
            ...$lists,
            'counts' => $counts,
            'canConnect' => $canConnect,
            'canManage' => $this->canManage(),
            'canSettings' => $this->user()->hasTeamPermission($company, 'settings:manage'),
            // Only for those who set the webhook up (review P3-10).
            'webhookUrl' => $connection === null || ! $canConnect ? null : route('saas.stripe.webhook', ['token' => $connection->webhook_token]),
        ]);
    }
}

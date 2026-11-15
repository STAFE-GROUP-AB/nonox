@php
    use App\Modules\Saas\Stripe\Actions\ConnectStripe;
    use App\Modules\Saas\Stripe\Models\StripeConnection;
    use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
    use App\Modules\Saas\Stripe\Support\StripeSync;
    use App\Support\Money\Money;

    $input = 'mt-1 block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $active = $connection !== null && $connection->status === StripeConnection::ACTIVE;
    $methods = [
        StripeCustomerLink::BY_METADATA => __('kundnummer i Stripe'),
        StripeCustomerLink::BY_TAX_ID => __('momsregistreringsnummer'),
        StripeCustomerLink::BY_EMAIL => __('samma e-postadress'),
        StripeCustomerLink::BY_NAME => __('samma namn och land'),
        StripeCustomerLink::BY_PERSON => __('kopplad av en person'),
        StripeCustomerLink::BY_CREATED => __('kund skapad från Stripe'),
    ];
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="__('Stripe')" :lead="__('Koppla ditt eget Stripe-konto med en begränsad nyckel som bara kan läsa. NONOX läser in prenumerationer och kunder och räknar dem i MRR, rörelser och kohorter. NONOX ändrar ingenting i Stripe och flyttar inga pengar.')" />

    @include('saas::partials.note')

    <x-ui.card :title="__('NONOX Connect')">
        <div class="space-y-3 text-sm text-gray-700">
            <p>{{ __('Stripe ingår i tillägget NONOX Connect. Priset är leverantörens pris till NONOX plus 10 %. Stripe tar inget betalt av NONOX för att läsa ditt konto, så priset blir 0 kr. Dina egna avgifter till Stripe betalar du som förut direkt till Stripe.') }}</p>
            <dl class="grid max-w-sm grid-cols-2 gap-x-4 gap-y-1" data-testid="stripe-price">
                <dt>{{ __('Leverantörens pris') }}</dt>
                <dd class="text-right tabular-nums">{{ Money::ofOre($price['provider_ore'])->format() }}</dd>
                <dt>{{ __('Påslag 10 %') }}</dt>
                <dd class="text-right tabular-nums">{{ Money::ofOre($price['markup_ore'])->format() }}</dd>
                <dt class="font-semibold text-ink">{{ __('Att betala per månad') }}</dt>
                <dd class="text-right font-semibold tabular-nums text-ink">{{ Money::ofOre($price['total_ore'])->format() }}</dd>
            </dl>
            @if($addonOn)
                <p><x-ui.badge tone="success">{{ __('Påslaget') }}</x-ui.badge></p>
                @if($canSettings && ! $active)
                    <button type="button" class="text-sm text-gray-700 underline" wire:click="disableAddon" wire:loading.attr="disabled">{{ __('Stäng av Stripe i NONOX Connect') }}</button>
                @endif
            @elseif($canSettings)
                <div x-data="{ confirming: false }">
                    <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ __('Slå på Stripe i NONOX Connect') }}</x-button>
                    <div x-show="confirming" x-cloak class="rounded-2xl bg-amber-50 p-4 text-amber-900 ring-1 ring-amber-200" role="alertdialog" aria-labelledby="stripe-addon-title">
                        <p id="stripe-addon-title" class="font-semibold">{{ __('Slå på Stripe i NONOX Connect?') }}</p>
                        <p class="mt-1">{{ __('Priset är 0 kr i månaden enligt raderna ovan. Ändras Stripes pris till NONOX får du veta det innan något debiteras.') }}</p>
                        <div class="mt-3 flex flex-wrap gap-3">
                            <x-button type="button" wire:click="enableAddon" wire:loading.attr="disabled">{{ __('Ja, slå på') }}</x-button>
                            <button type="button" class="text-sm text-gray-700 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                        </div>
                    </div>
                </div>
            @else
                <p>{{ __('En person med behörighet att ändra inställningar kan slå på Stripe i NONOX Connect.') }}</p>
            @endif
        </div>
    </x-ui.card>

    @if($addonOn)
        <x-ui.card :title="__('Kopplingen')">
            <div class="space-y-4 text-sm text-gray-700">
                @if($active)
                    <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-[max-content_1fr]" data-testid="stripe-connection">
                        <dt class="font-medium">{{ __('Stripe-konto') }}</dt>
                        <dd>{{ $connection->account_id }} @if(! $connection->livemode)<x-ui.badge tone="warning">{{ __('Testläge') }}</x-ui.badge>@endif</dd>
                        <dt class="font-medium">{{ __('Nyckel') }}</dt>
                        <dd class="font-mono">{{ $connection->maskedKey() }}</dd>
                        <dt class="font-medium">{{ __('Kopplad') }}</dt>
                        <dd>{{ $connection->connected_at->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</dd>
                        <dt class="font-medium">{{ __('Senast inläst') }}</dt>
                        <dd>{{ $connection->last_synced_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') ?? __('Inte än') }}</dd>
                    </dl>
                    @if($canManage)
                        <x-button type="button" wire:click="syncNow" wire:loading.attr="disabled">{{ __('Läs in nu') }}</x-button>
                    @endif
                @else
                    @if($connection !== null && $connection->status === StripeConnection::REVOKED)
                        <p class="rounded-xl bg-red-50 p-3 text-red-800 ring-1 ring-red-200">{{ __('Stripe godtar inte längre nyckeln, så kopplingen avslutades :time. Prenumerationerna räknas inte från den dagen. Koppla igen med en ny begränsad nyckel.', ['time' => $connection->ended_at?->timezone('Europe/Stockholm')->format('Y-m-d')]) }}</p>
                    @endif
                    <div class="space-y-2">
                        <p class="font-semibold text-ink">{{ __('Så skapar du nyckeln i Stripe') }}</p>
                        <ol class="list-decimal space-y-1 pl-5">
                            <li>{{ __('Gå till API-nycklar i Stripe och välj Skapa begränsad nyckel (Create restricted key).') }}</li>
                            <li>{{ __('Ge nyckeln behörigheten Read för: :resources.', ['resources' => implode(', ', ConnectStripe::PERMISSIONS)]) }}</li>
                            <li>{{ __('Låt allt annat stå på None, särskilt betalmetoder, Checkout, Connect och all skrivbehörighet (Write).') }}</li>
                            <li>{{ __('Kopiera nyckeln, som börjar med rk_live_, och klistra in den här.') }}</li>
                        </ol>
                        <p>{{ __('Stripe beskriver begränsade nycklar på sin sida om restricted API keys:') }} <span class="font-mono">docs.stripe.com/keys/restricted-api-keys</span></p>
                    </div>
                @endif

                @if($canConnect)
                    <form wire:submit="connect" class="max-w-xl space-y-3">
                        <x-ui.field for="stripe_key" :label="$active ? __('Byt nyckel') : __('Begränsad nyckel')" error="key" :hint="__('Nyckeln sparas krypterad och visas aldrig igen. NONOX sparar bara de fyra sista tecknen.')">
                            <input id="stripe_key" type="password" autocomplete="off" spellcheck="false" class="{{ $input }} font-mono" wire:model="key" placeholder="rk_live_…">
                        </x-ui.field>
                        <x-button type="submit" wire:loading.attr="disabled">{{ $active ? __('Byt nyckel') : __('Koppla Stripe') }}</x-button>
                    </form>
                @elseif(! $active)
                    <p>{{ __('En person med behörighet att ändra inställningar och att hantera återkommande intäkter kan koppla Stripe.') }}</p>
                @endif

                @if($active && $canConnect)
                    <div x-data="{ confirming: false }" class="border-t border-stone-100 pt-4">
                        <button type="button" class="text-sm font-semibold text-red-700 underline" x-show="! confirming" x-on:click="confirming = true">{{ __('Koppla från Stripe') }}</button>
                        <div x-show="confirming" x-cloak class="rounded-2xl bg-amber-50 p-4 text-amber-900 ring-1 ring-amber-200" role="alertdialog" aria-labelledby="stripe-disconnect-title">
                            <p id="stripe-disconnect-title" class="font-semibold">{{ __('Koppla från Stripe?') }}</p>
                            <p class="mt-1">{{ __('Nyckeln raderas i NONOX direkt. Prenumerationerna från Stripe räknas inte från i dag; stängda månader ändras inte. Det som är inläst raderas 90 dagar efter frånkopplingen. Låt också nyckeln gå ut i Stripe.') }}</p>
                            <div class="mt-3 flex flex-wrap gap-3">
                                <x-button type="button" wire:click="disconnect" wire:loading.attr="disabled">{{ __('Ja, koppla från') }}</x-button>
                                <button type="button" class="text-sm text-gray-700 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </x-ui.card>

        @if($active)
            <x-ui.card :title="__('Webhook (valfritt)')">
                <div class="space-y-3 text-sm text-gray-700">
                    <p>{{ __('Utan webhook läser NONOX ändringar från Stripe varje timme. Med en webhook kommer de fram inom någon minut. Lägg till en händelsemottagare (event destination) i Stripe med adressen nedan och händelserna i listan, och klistra in dess signeringshemlighet.') }}</p>
                    @if($canConnect)
                        <p class="break-all font-mono" data-testid="stripe-webhook-url">{{ $webhookUrl }}</p>
                        <details>
                            <summary class="cursor-pointer text-accent-700">{{ __('Händelser att välja i Stripe') }}</summary>
                            <p class="mt-2 font-mono text-xs">{{ implode(', ', StripeSync::EVENT_TYPES) }}</p>
                        </details>
                        <form wire:submit="saveWebhook" class="max-w-xl space-y-3">
                            <x-ui.field for="stripe_webhook_secret" :label="__('Signeringshemlighet')" error="webhookSecret" :hint="$connection->hasWebhook() ? __('En signeringshemlighet är sparad. Fyll bara i fältet för att byta den, eller lämna det tomt och spara för att ta bort den.') : __('Den börjar med whsec_. Den sparas krypterad och visas aldrig igen.')">
                                <input id="stripe_webhook_secret" type="password" autocomplete="off" spellcheck="false" class="{{ $input }} font-mono" wire:model="webhookSecret" placeholder="whsec_…">
                            </x-ui.field>
                            <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                        </form>
                    @else
                        <p>{{ $connection->hasWebhook() ? __('En webhook är inställd.') : __('Ingen webhook är inställd.') }}</p>
                    @endif
                </div>
            </x-ui.card>
        @endif

        @if($connection !== null)
            <x-ui.card :title="__('Kunder i Stripe')">
                <div class="space-y-6 text-sm text-gray-700">
                    <p>{{ __('NONOX kopplar en Stripe-kund till en av dina kunder när kundnumret står i Stripe (metadata nonox_customer_number) eller när momsregistreringsnumret stämmer. Samma e-postadress, eller samma namn och land, blir bara ett förslag. En Stripe-kund som inte är kopplad räknas som en egen kund tills du kopplar den; kopplar du den senare flyttas dess historik och visas som rättelse i stängda månader.') }}</p>
                    <p data-testid="stripe-counts">
                        {{ __('Stripe-kunder med prenumeration. Kopplade: :linked. Att granska: :review. Förslag: :suggested. Inte kopplade: :unlinked.', [
                            'linked' => $counts[StripeCustomerLink::LINKED]['count'] ?? 0,
                            'review' => $counts[StripeCustomerLink::LINKED]['review'] ?? 0,
                            'suggested' => $counts[StripeCustomerLink::SUGGESTED]['count'] ?? 0,
                            'unlinked' => $counts[StripeCustomerLink::UNLINKED]['count'] ?? 0,
                        ]) }}
                    </p>

                    @foreach([
                        ['rows' => $review, 'title' => __('Kopplade automatiskt, att granska'), 'id' => 'granska'],
                        ['rows' => $suggested, 'title' => __('Förslag'), 'id' => 'forslag'],
                        ['rows' => $unlinked, 'title' => __('Inte kopplade'), 'id' => 'kunder'],
                    ] as $section)
                        @if($section['rows']->isNotEmpty())
                            <section id="{{ $section['id'] }}" aria-labelledby="stripe-{{ $section['id'] }}">
                                <h3 id="stripe-{{ $section['id'] }}" class="font-semibold text-ink">{{ $section['title'] }}</h3>
                                <ul class="mt-2 divide-y divide-stone-100" data-testid="stripe-{{ $section['id'] }}">
                                    @foreach($section['rows'] as $link)
                                        <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="stripe-link-{{ $link->id }}">
                                            <div>
                                                <p class="font-medium text-ink">{{ $link->label() }}</p>
                                                <p class="text-gray-600">{{ collect([$link->email, $link->country, $link->stripe_customer_id])->filter()->implode(' · ') }}</p>
                                                @if($link->isLinked())
                                                    <p>{{ __('Kopplad till :number :name (:method).', ['number' => $link->customer?->number, 'name' => $link->customer?->name, 'method' => $methods[$link->method] ?? '']) }}</p>
                                                @elseif($link->suggested !== null)
                                                    <p>{{ __('Förslag: :number :name (:method).', ['number' => $link->suggested->number, 'name' => $link->suggested->name, 'method' => $methods[$link->suggestion_method] ?? '']) }}</p>
                                                @endif
                                            </div>
                                            @if($canManage)
                                                <div class="flex flex-wrap items-end gap-2">
                                                    @if($link->isLinked())
                                                        <x-button type="button" wire:click="confirm({{ $link->id }})" wire:loading.attr="disabled">{{ __('Stämmer') }}</x-button>
                                                        <button type="button" class="text-sm text-gray-700 underline" wire:click="unlink({{ $link->id }})" wire:loading.attr="disabled">{{ __('Koppla bort') }}</button>
                                                    @else
                                                        @if($link->suggested !== null)
                                                            <x-button type="button" wire:click="acceptSuggestion({{ $link->id }})" wire:loading.attr="disabled">{{ __('Koppla till förslaget') }}</x-button>
                                                        @endif
                                                        <form wire:submit="linkTo({{ $link->id }})" class="flex items-end gap-2">
                                                            <div>
                                                                <label for="stripe-number-{{ $link->id }}" class="block text-xs text-gray-600">{{ __('Kundnummer') }}</label>
                                                                <input id="stripe-number-{{ $link->id }}" type="text" inputmode="numeric" class="mt-1 block w-28 rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model="numbers.{{ $link->id }}">
                                                            </div>
                                                            <button type="submit" class="min-h-11 text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Koppla') }}</button>
                                                        </form>
                                                        <button type="button" class="min-h-11 text-sm text-gray-700 underline" wire:click="createCustomer({{ $link->id }})" wire:loading.attr="disabled">{{ __('Skapa kund') }}</button>
                                                    @endif
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Inläsningar')">
                @if($runs->isEmpty())
                    <p class="text-sm text-gray-700">{{ __('Ingen inläsning än.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm" data-testid="stripe-runs">
                            <caption class="sr-only">{{ __('Inläsningar från Stripe') }}</caption>
                            <thead>
                                <tr class="text-left text-gray-600">
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Start') }}</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Slag') }}</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Anrop') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Kunder') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Prenumerationer') }}</th>
                                    <th scope="col" class="py-2 text-right font-medium">{{ __('Nya versioner') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach($runs as $run)
                                    <tr>
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ $run->started_at->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</td>
                                        <td class="py-2 pr-4">{{ $run->kindLabel() }}</td>
                                        <td class="py-2 pr-4">
                                            <x-ui.badge :tone="match ($run->status) { 'OK' => 'success', 'FAILED' => 'danger', 'INTERRUPTED' => 'warning', default => 'neutral' }">{{ $run->statusLabel() }}</x-ui.badge>
                                            @if($run->errorLabel() !== null)
                                                <span class="block text-gray-600">{{ $run->errorLabel() }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $run->requests }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $run->counts['customers'] ?? 0 }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $run->counts['subscriptions'] ?? 0 }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ $run->counts['versions'] ?? 0 }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        @endif
    @endif
</div>

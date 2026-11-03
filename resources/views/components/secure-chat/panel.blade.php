{{--
    The encrypted chat (ADR-045): a docked panel, bottom right on a desktop and
    a full-height sheet on a phone. All text is written with x-text, never as
    HTML. The logic is resources/js/secure-chat.js.
--}}
@props(['company' => null])

@php
    $chatConfig = [
        'company' => $company?->getKey(),
        'urls' => [
            'start' => route('chat.start'),
            'join' => route('chat.join'),
            'poll' => route('chat.poll'),
            'send' => route('chat.send'),
            'end' => route('chat.end'),
            'companies' => route('chat.companies'),
        ],
        't' => [
            'failed' => __('Det gick inte att nå chatten. Försök igen.'),
            'badCode' => __('Koden har tio tecken, till exempel K7Q4-XM9P-D2.'),
            'tooLong' => __('Meddelandet är för långt.'),
            'noAccess' => __('Du har inte tillgång till den sidan.'),
            'ended' => __('Chatten är avslutad. Inget av det som skrevs finns kvar.'),
            'peerJoined' => __(':name har anslutit till chatten.'),
            'joinedAs' => __('Du är ansluten till chatten med :name.'),
            'incoming' => __(':name skriver: :message'),
            'smsBody' => __('Koden till vår krypterade chatt i NONOX är :code. Välj Chatt och Anslut till chatt i NONOX och skriv in koden.'),
        ],
    ];
@endphp

@persist('secure-chat')
<div x-data="secureChat(@js($chatConfig))" x-on:keydown.escape="open && hide()">
    <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" x-text="announcement"></div>

    {{-- The company switch the follower can accept ("Byt till … och följ"); the switch is audited as usual. --}}
    <form x-ref="switchForm" method="POST" action="{{ route('current-team.update') }}" hidden>
        @csrf
        @method('PUT')
        <input type="hidden" name="team_id" x-ref="switchCompany">
    </form>

    <section x-ref="panel" x-show="open" x-cloak tabindex="-1" aria-labelledby="secure-chat-title"
             x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-150" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
             class="fixed inset-0 z-[60] flex flex-col bg-white pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] focus:outline-hidden sm:inset-auto sm:bottom-4 sm:right-4 sm:h-[34rem] sm:max-h-[calc(100vh-2rem)] sm:w-[24rem] sm:rounded-2xl sm:pt-0 sm:pb-0 sm:shadow-2xl sm:ring-1 sm:ring-stone-200">
        <header class="flex items-center gap-3 border-b border-stone-200 px-4 py-3">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-ink text-white" aria-hidden="true">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
            </span>
            <div class="min-w-0 flex-1">
                <h2 id="secure-chat-title" class="truncate text-sm font-semibold text-ink">
                    <span x-show="phase !== 'active' || !peer">{{ __('Krypterad chatt') }}</span>
                    <span x-show="phase === 'active' && peer" x-text="@js(__('Chatt med')) + ' ' + peer"></span>
                </h2>
                <p class="text-xs text-gray-500" x-show="key && closesAt" x-text="@js(__('Raderas efter 30 minuter utan meddelanden, senast kl.')) + ' ' + closesAt"></p>
            </div>
            <button type="button" x-on:click="hide()" class="rounded-lg p-2 text-gray-500 hover:bg-stone-100 hover:text-ink focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500" aria-label="{{ __('Dölj chatten') }}">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
        </header>

        {{-- Start: two choices. --}}
        <div x-show="phase === 'idle'" class="flex flex-1 flex-col gap-4 overflow-y-auto p-5">
            <p class="text-sm text-gray-700">{{ __('Chatta krypterat med supporten, er revisor eller en kund. Det som skrivs krypteras i webbläsaren och raderas helt när chatten avslutas.') }}</p>
            <button type="button" data-chat-focus x-on:click="start()" class="rounded-xl bg-accent-600 px-4 py-3 text-sm font-semibold text-white hover:bg-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2">{{ __('Starta chatt') }}</button>
            <button type="button" x-on:click="showJoin()" class="rounded-xl bg-white px-4 py-3 text-sm font-semibold text-ink ring-1 ring-stone-300 hover:bg-stone-50 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">{{ __('Anslut till chatt') }}</button>
            <p class="text-sm text-red-700" role="alert" x-show="error" x-text="error"></p>
        </div>

        <div x-show="phase === 'preparing'" class="flex flex-1 items-center justify-center p-5 text-sm text-gray-600" role="status">{{ __('Förbereder krypteringen …') }}</div>

        {{-- The starter waits: the code to read out or send. --}}
        <div x-show="phase === 'waiting'" class="flex flex-1 flex-col gap-4 overflow-y-auto p-5">
            <p class="text-sm text-gray-700">{{ __('Läs upp eller skicka koden. Den fungerar tills någon har anslutit.') }}</p>
            <div class="flex items-center justify-between gap-3 rounded-xl bg-stone-50 px-4 py-4 ring-1 ring-stone-200">
                <span class="font-mono text-2xl font-semibold tracking-wider text-ink tabular-nums" x-text="code" aria-label="{{ __('Koden') }}"></span>
                <button type="button" data-chat-focus x-on:click="copy()" class="rounded-lg px-3 py-1.5 text-sm font-medium text-accent-700 ring-1 ring-stone-300 hover:bg-white focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">
                    <span x-show="!copied">{{ __('Kopiera') }}</span>
                    <span x-show="copied">{{ __('Kopierad') }}</span>
                </button>
            </div>
            <a :href="smsHref" class="text-sm font-medium text-accent-700 underline underline-offset-2 hover:text-accent-800">{{ __('Skicka som sms') }}</a>
            <p class="text-sm text-gray-600">{{ __('Väntar på att någon ansluter …') }}</p>
        </div>

        {{-- Join: type the code. --}}
        <form x-show="phase === 'join'" x-on:submit.prevent="join()" class="flex flex-1 flex-col gap-4 overflow-y-auto p-5">
            <label for="secure-chat-code" class="text-sm font-medium text-ink">{{ __('Kod') }}</label>
            <input id="secure-chat-code" data-chat-focus type="text" x-model="codeInput" x-on:input="formatInput()" autocomplete="off" autocapitalize="characters" spellcheck="false" inputmode="text" maxlength="12"
                   placeholder="K7Q4-XM9P-D2" aria-describedby="secure-chat-code-hint" :aria-invalid="error ? 'true' : 'false'"
                   class="rounded-xl border-stone-300 font-mono text-xl tracking-wider uppercase focus:border-accent-500 focus:ring-accent-500">
            <p id="secure-chat-code-hint" class="text-sm text-gray-600">{{ __('Koden du fick uppläst eller i ett sms. Stora eller små bokstäver spelar ingen roll.') }}</p>
            <p class="text-sm text-red-700" role="alert" x-show="error" x-text="error"></p>
            <div class="flex gap-3">
                <button type="submit" :disabled="busy" class="flex-1 rounded-xl bg-accent-600 px-4 py-3 text-sm font-semibold text-white hover:bg-accent-700 disabled:opacity-60 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2">{{ __('Anslut') }}</button>
                <button type="button" x-on:click="restart()" class="rounded-xl px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-stone-300 hover:bg-stone-50 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">{{ __('Tillbaka') }}</button>
            </div>
        </form>

        {{-- The conversation. --}}
        <div x-show="phase === 'active'" class="flex min-h-0 flex-1 flex-col">
            <ol x-ref="log" class="flex-1 space-y-3 overflow-y-auto px-4 py-4" aria-label="{{ __('Meddelanden') }}">
                <template x-for="message in messages" :key="message.seq">
                    <li :class="message.kind === 'nav' ? 'text-center' : (message.mine ? 'flex flex-col items-end' : 'flex flex-col items-start')">
                        <template x-if="message.kind === 'msg'">
                            <div class="max-w-[85%]">
                                <p class="mb-0.5 text-xs text-gray-500" :class="message.mine ? 'text-right' : ''">
                                    <span x-text="message.mine ? @js(__('Du')) : peer"></span> · <time x-text="time(message.at)"></time>
                                </p>
                                <p class="whitespace-pre-wrap break-words rounded-2xl px-3.5 py-2 text-sm" :class="message.mine ? 'bg-ink text-white' : 'bg-stone-100 text-ink'" x-text="message.body"></p>
                            </div>
                        </template>
                        <template x-if="message.kind === 'nav'">
                            <p class="text-xs text-gray-500">
                                <span x-text="message.mine ? @js(__('Du öppnade')) : (peer + ' ' + @js(__('öppnade')))"></span>
                                <span class="font-mono" x-text="message.body"></span>
                            </p>
                        </template>
                    </li>
                </template>
                <li x-show="messages.length === 0" class="text-center text-sm text-gray-500">{{ __('Inga meddelanden ännu.') }}</li>
            </ol>

            {{-- Följ mina sidor, for the starter; pausing, for the one who follows. --}}
            <div class="space-y-2 border-t border-stone-200 px-4 py-2 text-sm">
                <label x-show="isStarter" class="flex items-center gap-2 text-gray-700">
                    <input type="checkbox" :checked="state.follow === true" x-on:change="toggleFollow()" class="rounded border-stone-300 text-accent-600 focus:ring-accent-500">
                    {{ __('Följ mina sidor') }}
                </label>
                <div x-show="!isStarter && state.lastNav" class="flex items-center justify-between gap-2 text-gray-700">
                    <span x-text="state.paused ? @js(__('Följningen är pausad.')) : @js(__('Du följer sidorna som den andra öppnar.'))"></span>
                    <button type="button" x-on:click="togglePause()" class="rounded-lg px-2 py-1 font-medium text-accent-700 hover:bg-stone-100 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500"
                            x-text="state.paused ? @js(__('Följ igen')) : @js(__('Pausa'))"></button>
                </div>
                <p x-show="notice" x-text="notice" role="status" class="text-amber-800"></p>
                <div x-show="offer" class="flex items-center justify-between gap-2">
                    <button type="button" x-on:click="acceptOffer()" class="rounded-lg bg-ink px-3 py-1.5 font-medium text-white hover:bg-black focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500"
                            x-text="offer ? @js(__('Byt till')) + ' ' + offer.company.name + ' ' + @js(__('och följ')) : ''"></button>
                    <button type="button" x-on:click="offer = null" class="rounded-lg px-2 py-1 text-gray-600 hover:bg-stone-100">{{ __('Inte nu') }}</button>
                </div>
            </div>

            <form x-on:submit.prevent="send()" class="border-t border-stone-200 p-3">
                <label for="secure-chat-draft" class="sr-only">{{ __('Meddelande') }}</label>
                <textarea id="secure-chat-draft" x-ref="draft" data-chat-focus x-model="draft" x-on:keydown="keydown($event)" rows="2" :maxlength="maxLength"
                          aria-describedby="secure-chat-hint" placeholder="{{ __('Skriv ett meddelande') }}"
                          class="block w-full resize-none rounded-xl border-stone-300 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
                <p class="mt-1 text-sm text-red-700" role="alert" x-show="error" x-text="error"></p>
                <div class="mt-2 flex items-center justify-between gap-2">
                    <p id="secure-chat-hint" class="text-xs text-gray-500">
                        <span x-show="remaining > 100">{{ __('Enter skickar, Skift+Enter ger ny rad.') }}</span>
                        <span x-show="remaining <= 100" x-text="remaining + ' ' + @js(__('tecken kvar'))"></span>
                    </p>
                    <button type="submit" :disabled="busy || draft.trim() === ''" class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700 disabled:opacity-60 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2">{{ __('Skicka') }}</button>
                </div>
            </form>
        </div>

        {{-- Ended, by either side or by time. --}}
        <div x-show="phase === 'ended'" class="flex flex-1 flex-col items-center justify-center gap-4 p-6 text-center">
            <p class="text-sm font-medium text-ink">{{ __('Chatten är avslutad. Inget av det som skrevs finns kvar.') }}</p>
            <button type="button" data-chat-focus x-on:click="restart()" class="rounded-xl px-4 py-2 text-sm font-medium text-ink ring-1 ring-stone-300 hover:bg-stone-50 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">{{ __('Ny chatt') }}</button>
        </div>

        <footer class="flex items-start gap-2 border-t border-stone-200 bg-stone-50 px-4 py-2.5 text-xs text-gray-600 sm:rounded-b-2xl">
            <svg class="mt-0.5 size-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
            <span class="flex-1" x-show="!confirmingEnd">{{ __('Krypterad från din webbläsare till den andras. Ingenting sparas när chatten stängs.') }}</span>
            <button type="button" x-show="key && !confirmingEnd" x-on:click="confirmingEnd = true; $nextTick(() => $refs.confirmEnd.focus())" class="shrink-0 font-semibold text-red-700 hover:text-red-800 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">{{ __('Avsluta chatt') }}</button>
            {{-- Ending cannot be undone: say what happens first (AGENTS.md §11.3). --}}
            <span class="flex-1 font-medium text-ink" x-show="confirmingEnd">{{ __('Avsluta chatten för er båda? Allt som skrivits raderas och kan inte tas fram igen.') }}</span>
            <span class="flex shrink-0 gap-3" x-show="confirmingEnd">
                <button type="button" x-ref="confirmEnd" x-on:click="confirmingEnd = false; end()" class="font-semibold text-red-700 hover:text-red-800 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">{{ __('Avsluta') }}</button>
                <button type="button" x-on:click="confirmingEnd = false" class="font-medium text-gray-700 hover:text-ink focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">{{ __('Avbryt') }}</button>
            </span>
        </footer>
    </section>
</div>
@endpersist

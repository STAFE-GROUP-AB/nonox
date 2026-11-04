@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $zone = 'Europe/Stockholm';
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('crm.mailings') }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700">← {{ __('Alla utskick') }}</a>
        <x-ui.badge :tone="$draft ? 'neutral' : 'accent'">{{ $mailing->status->label() }}</x-ui.badge>
    </div>

    @if($notice)
        <div class="rounded-2xl bg-accent-50 px-4 py-3 text-sm text-accent-900 ring-1 ring-accent-200" role="status">{{ $notice }}</div>
    @endif

    @if($draft)
        @if(! $hasAddress)
            <div class="rounded-2xl bg-accent-50 px-4 py-3 text-sm text-accent-900 ring-1 ring-accent-200" role="status">{{ __('Varje utskick ska visa företagets postadress. Fyll i adress, postnummer och ort under Företagsinställningar innan du skickar.') }}</div>
        @endif

        <div class="grid gap-6 xl:grid-cols-5">
            <x-ui.card class="xl:col-span-3" :title="__('Utskicket')">
                <form wire:submit="save" class="mt-4 grid gap-4">
                    <x-ui.field for="mailing_name" :label="__('Namn')" error="form.name" :hint="__('Syns bara för er.')">
                        <input id="mailing_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" maxlength="100">
                    </x-ui.field>
                    <x-ui.field for="mailing_segment" :label="__('Segment')" error="form.segment_id" :hint="__('Ett segment är ett sparat filter. Vilka som får utskicket räknas fram när du väljer Ja, skicka, också om det är schemalagt. Rutan Mottagare visar hur många det blir och vilka som lämnas utanför.')">
                        <select id="mailing_segment" class="{{ $input }} mt-1" wire:model.live="form.segment_id">
                            <option value="">{{ __('Välj segment') }}</option>
                            @foreach($segments as $segment)
                                <option value="{{ $segment->id }}">{{ $segment->name }}</option>
                            @endforeach
                        </select>
                        <a href="{{ route('crm.segments') }}" class="mt-1 inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0">{{ __('Så fungerar segment') }}</a>
                    </x-ui.field>
                    <x-ui.field for="mailing_subject" :label="__('Ämne')" error="form.subject">
                        <input id="mailing_subject" type="text" class="{{ $input }} mt-1" wire:model="form.subject" maxlength="200">
                    </x-ui.field>
                    <x-ui.field for="mailing_body" :label="__('Text (Markdown)')" error="form.body" :hint="__('**fetstil**, *kursiv*, # rubrik, - lista och [länktext](https://adress). Fält: :fields', ['fields' => collect(array_keys($fields))->map(fn ($field) => '{'.$field.'}')->implode(' ')])">
                        <textarea id="mailing_body" rows="14" class="{{ $input }} mt-1 font-mono text-sm" wire:model="form.body"></textarea>
                    </x-ui.field>
                    <label class="flex items-start gap-3 rounded-2xl bg-stone-50 p-4 text-sm ring-1 ring-stone-200">
                        <input type="checkbox" class="mt-0.5 size-5 rounded border-gray-300" wire:model.live="form.customer_exception">
                        <span>
                            <span class="block font-semibold text-ink">{{ __('Kundundantaget: befintliga kunder som är privatpersoner') }}</span>
                            <span class="block text-gray-700">{{ __('Kryssa bara i om allt stämmer: personerna är redan kunder, ni fick adressen när de köpte, utskicket gäller era egna liknande produkter, och de kunde säga nej när ni fick adressen. Avregistreringslänken finns i varje utskick (marknadsföringslagen 19 §).') }}</span>
                        </span>
                    </label>
                    @error('send')<p class="text-sm font-medium text-accent-700" role="alert">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap items-center gap-3">
                        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara utkast') }}</x-button>
                        <button type="button" class="inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-semibold text-ink ring-1 ring-stone-300 hover:bg-stone-50" wire:click="sendTest" wire:loading.attr="disabled">{{ __('Skicka test till mig') }}</button>
                        <button type="button" class="min-h-11 text-sm text-gray-700 underline" wire:click="delete" wire:confirm="{{ __('Ta bort utkastet?') }}">{{ __('Ta bort') }}</button>
                    </div>
                </form>
            </x-ui.card>

            <div class="space-y-6 xl:col-span-2">
                <x-ui.card :title="__('Mottagare')">
                    @if($audience['total'] === 0)
                        <p class="mt-3 text-sm text-gray-600">{{ __('Välj ett segment för att se vilka som får utskicket.') }}</p>
                    @else
                        <p class="mt-3 text-3xl font-bold tabular-nums text-ink" data-testid="crm-mailing-recipients">{{ count($audience['recipients']) }}</p>
                        <p class="text-sm text-gray-600">{{ __('får utskicket av :total personer i segmentet.', ['total' => $audience['total']]) }}</p>
                        @if($audience['excluded'] !== [])
                            <h3 class="mt-4 text-sm font-semibold text-ink">{{ __('Får det inte, och varför') }}</h3>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700" data-testid="crm-mailing-excluded">
                                @foreach($exclusions as $exclusion)
                                    @if(isset($audience['excluded'][$exclusion->value]))
                                        <li class="flex justify-between gap-3"><span>{{ $exclusion->label() }}</span><span class="tabular-nums font-medium">{{ $audience['excluded'][$exclusion->value] }}</span></li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                        <p class="mt-4 text-xs text-gray-600">{{ __('Kontaktpersoner på aktiebolag och andra juridiska personer får utskick med stöd av berättigat intresse. Privatpersoner och enskilda firmor behöver samtycke, eller kundundantaget. Om reglerna:') }} <a href="{{ route('manual.article', ['crm', 'utskick']) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Utskick') }}</a></p>
                    @endif

                    @if($audience['recipients'] !== [])
                        <div class="mt-5 border-t border-gray-100 pt-4">
                            @if($confirmSend)
                                <div class="space-y-3" role="alertdialog" aria-labelledby="confirm-send-title">
                                    <p id="confirm-send-title" class="text-sm font-semibold text-ink">{{ __('Skicka till :count personer? Ett skickat utskick går inte att ta tillbaka.', ['count' => count($audience['recipients'])]) }}</p>
                                    <x-ui.field for="mailing_when" :label="__('Skicka senare (frivilligt)')" error="scheduledAt">
                                        <input id="mailing_when" type="datetime-local" class="{{ $input }} mt-1" wire:model="scheduledAt">
                                    </x-ui.field>
                                    <div class="flex flex-wrap gap-3">
                                        <x-button type="button" wire:click="send" wire:loading.attr="disabled">{{ __('Ja, skicka') }}</x-button>
                                        <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="$set('confirmSend', false)">{{ __('Avbryt') }}</button>
                                    </div>
                                </div>
                            @else
                                <x-button type="button" wire:click="askSend" wire:loading.attr="disabled">{{ __('Skicka eller schemalägg') }}</x-button>
                            @endif
                        </div>
                    @endif
                </x-ui.card>
            </div>
        </div>
    @else
        <x-ui.card :title="$mailing->name" :description="$mailing->subject">
            <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6" data-testid="crm-mailing-stats">
                @foreach([
                    __('Mottagare') => $mailing->recipient_count,
                    __('Skickade') => $mailing->sent_count,
                    __('Levererade') => $mailing->delivered_count,
                    __('Studsade') => $mailing->bounced_count,
                    __('Klick') => $mailing->clicks,
                    __('Avregistrerade') => $mailing->unsubscribed_count,
                ] as $label => $count)
                    <div class="rounded-2xl bg-stone-50 p-4 ring-1 ring-stone-200">
                        <dt class="text-xs font-medium text-gray-600">{{ $label }}</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-3 text-sm text-gray-600">
                @if($mailing->scheduled_at && $mailing->status === App\Modules\Crm\Domain\MailingStatus::Scheduled)
                    {{ __('Schemalagt till :time.', ['time' => $mailing->scheduled_at->setTimezone($zone)->translatedFormat('j M Y H:i')]) }}
                @elseif($mailing->started_at)
                    {{ __('Började skickas :time.', ['time' => $mailing->started_at->setTimezone($zone)->translatedFormat('j M Y H:i')]) }}
                @endif
                {{ __('Levererade och studsade räknas när e-posttjänsten rapporterar dem.') }} {{ __('Klick räknas per länk. NONOX vet inte vem som klickade, och öppningar mäts inte.') }}
            </p>
            @if($mailing->failed_count > 0)
                <p class="mt-2 text-sm font-medium text-accent-700">{{ __(':count meddelanden kunde inte skickas.', ['count' => $mailing->failed_count]) }}</p>
            @endif
            @if(in_array($mailing->status, [App\Modules\Crm\Domain\MailingStatus::Preparing, App\Modules\Crm\Domain\MailingStatus::Scheduled, App\Modules\Crm\Domain\MailingStatus::Sending], true))
                <button type="button" class="mt-4 min-h-11 text-sm font-medium text-accent-700 underline" wire:click="cancel" wire:confirm="{{ __('Avbryta utskicket? Det som inte har skickats än skickas inte.') }}">{{ __('Avbryt utskicket') }}</button>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Klick per länk')" :description="__('Klick räknas per länk. NONOX vet inte vem som klickade, och öppningar mäts inte.')">
            @if($links->isEmpty())
                <p class="mt-3 text-sm text-gray-600">{{ __('Utskicket har inga länkar.') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm" data-testid="crm-mailing-links">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-600">
                                <th scope="col" class="py-2 pe-4 font-medium">{{ __('Länk') }}</th>
                                <th scope="col" class="py-2 text-right font-medium">{{ __('Klick') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($links as $link)
                                <tr wire:key="link-{{ $link->id }}">
                                    <td class="max-w-md break-all py-2 pe-4">{{ $link->url }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ $link->clicks }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Förhandsvisning')" :description="$draft ? __('Så ser det sparade utkastet ut, med dina egna uppgifter i fälten. Spara för att se ändringar.') : __('Det som skickades, med dina egna uppgifter i fälten.')">
        <iframe wire:key="preview-{{ $mailing->updated_at?->timestamp }}" src="{{ route('crm.mailings.preview', $mailing) }}" title="{{ __('Förhandsvisning av utskicket') }}" sandbox="" class="mt-4 h-[36rem] w-full rounded-2xl ring-1 ring-stone-200"></iframe>
    </x-ui.card>
</div>

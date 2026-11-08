@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-8">
    @if($canManage && ! $open)
        <div class="flex flex-wrap gap-3">
            <x-button type="button" wire:click="create('TRANSFER')">{{ __('Ny händelse') }}</x-button>
        </div>
    @endif

    @if($open && $kind !== null)
        <x-ui.card :title="__('Ny händelse: :kind', ['kind' => $kind->label()])" :description="__('Händelsen kan inte ändras när den är införd. Kontrollera aktienumren och datumen.')">
            <form wire:submit="save" class="mt-4 grid gap-5 sm:grid-cols-2">
                <x-ui.field for="event-kind" :label="__('Händelse')" error="form.kind" class="sm:col-span-2">
                    <select id="event-kind" wire:model.live="form.kind" class="{{ $input }}">
                        @foreach($kinds as $each)
                            <option value="{{ $each->value }}">{{ $each->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>

                @if($kind === \App\Modules\Boardroom\Domain\ShareEventKind::Opening)
                    <div class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200 sm:col-span-2" role="note">
                        {{ __('För in aktieboken som den ser ut i dag, en rad per ägare och nummerserie. Spara den gamla aktieboken i minst tio år efter att alla aktier har förts in här (aktiebolagslagen 5 kap. 3 §).') }}
                    </div>
                @endif

                @if($kind->effectiveOnRegistration())
                    <x-ui.field for="event-registered" :label="__('Registrerad hos Bolagsverket')" error="form.registered_on" :hint="__('Ändringen gäller från registreringen.')">
                        <input id="event-registered" type="date" wire:model="form.registered_on" class="{{ $input }}" required>
                    </x-ui.field>
                @else
                    <x-ui.field for="event-date" :label="__('Gäller från')" error="form.effective_date" :hint="$kind === \App\Modules\Boardroom\Domain\ShareEventKind::Formation ? __('Dagen då alla stiftare skrev under stiftelseurkunden.') : null">
                        <input id="event-date" type="date" wire:model="form.effective_date" class="{{ $input }}" required>
                    </x-ui.field>
                @endif

                @if(in_array($kind, [\App\Modules\Boardroom\Domain\ShareEventKind::NewIssue, \App\Modules\Boardroom\Domain\ShareEventKind::BonusIssue, \App\Modules\Boardroom\Domain\ShareEventKind::Split, \App\Modules\Boardroom\Domain\ShareEventKind::ReverseSplit, \App\Modules\Boardroom\Domain\ShareEventKind::Redemption, \App\Modules\Boardroom\Domain\ShareEventKind::Conversion], true))
                    <x-ui.field for="event-decided" :label="__('Beslutad')" error="form.decided_on">
                        <input id="event-decided" type="date" wire:model="form.decided_on" class="{{ $input }}">
                    </x-ui.field>
                @endif

                @if($kind->needsRange() || $kind === \App\Modules\Boardroom\Domain\ShareEventKind::BonusIssue)
                    <x-ui.field for="event-from" :label="__('Från aktie nummer')" error="form.share_from">
                        <input id="event-from" type="number" min="1" wire:model="form.share_from" class="{{ $input }}" @required($kind->needsRange())>
                    </x-ui.field>
                    <x-ui.field for="event-to" :label="__('Till aktie nummer')" error="form.share_to">
                        <input id="event-to" type="number" min="1" wire:model="form.share_to" class="{{ $input }}" @required($kind->needsRange())>
                    </x-ui.field>
                @endif

                @if(($kind->issuesShares() || $kind === \App\Modules\Boardroom\Domain\ShareEventKind::Conversion || $kind === \App\Modules\Boardroom\Domain\ShareEventKind::BonusIssue) && $classes->count() > 0)
                    <x-ui.field for="event-class" :label="__('Aktieslag')" error="form.share_class_id">
                        <select id="event-class" wire:model="form.share_class_id" class="{{ $input }}">
                            <option value="">{{ $classes->count() === 1 ? $classes->first()->name : __('Välj') }}</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}">{{ $class->code }} – {{ $class->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif

                @if($kind->needsSender() || $kind === \App\Modules\Boardroom\Domain\ShareEventKind::Redemption)
                    <x-ui.field for="event-from-holder" :label="__('Från ägare')" error="form.from_holder_id">
                        <select id="event-from-holder" wire:model="form.from_holder_id" class="{{ $input }}" @required($kind->needsSender())>
                            <option value="">{{ __('Välj') }}</option>
                            @foreach($holders as $holder)
                                <option value="{{ $holder->id }}">{{ $holder->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif

                @if($kind->needsRecipient() || $kind === \App\Modules\Boardroom\Domain\ShareEventKind::BonusIssue)
                    <x-ui.field for="event-to-holder" :label="$kind->needsSender() ? __('Till ägare') : __('Ägare')" error="form.to_holder_id" :hint="$holders->isEmpty() ? __('Lägg först till ägaren under Aktieägare.') : null">
                        <select id="event-to-holder" wire:model="form.to_holder_id" class="{{ $input }}" @required($kind->needsRecipient())>
                            <option value="">{{ __('Välj') }}</option>
                            @foreach($holders as $holder)
                                <option value="{{ $holder->id }}">{{ $holder->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif

                @if($kind->issuesShares() || $kind === \App\Modules\Boardroom\Domain\ShareEventKind::BonusIssue || $kind === \App\Modules\Boardroom\Domain\ShareEventKind::Redemption)
                    <x-ui.field for="event-capital" :label="$kind === \App\Modules\Boardroom\Domain\ShareEventKind::Redemption ? __('Minskning av aktiekapitalet (kr, negativt)') : __('Aktiekapital för aktierna (kr)')" error="form.capital_change_ore" :hint="__('Antal aktier gånger kvotvärdet.')">
                        <input id="event-capital" wire:model="form.capital" inputmode="decimal" class="{{ $input }}" required>
                    </x-ui.field>
                @endif

                @if(in_array($kind, [\App\Modules\Boardroom\Domain\ShareEventKind::Transfer, \App\Modules\Boardroom\Domain\ShareEventKind::NewIssue], true))
                    <x-ui.field for="event-consideration" :label="__('Pris eller teckningsbelopp (kr)')" error="form.consideration_ore">
                        <input id="event-consideration" wire:model="form.consideration" inputmode="decimal" class="{{ $input }}">
                    </x-ui.field>
                @endif

                @if($kind->issuesShares() || $kind === \App\Modules\Boardroom\Domain\ShareEventKind::Certificate)
                    <label class="flex items-center gap-3 sm:col-span-2"><x-checkbox wire:model="form.certificate_issued" /> <span>{{ __('Aktiebrev är utfärdat') }}</span></label>
                @endif

                @if($kind->needsRatio())
                    <x-ui.field for="event-ratio" :label="$kind === \App\Modules\Boardroom\Domain\ShareEventKind::Split ? __('Varje aktie blir (antal)') : __('Antal aktier som blir en')" error="form.ratio" :hint="$kind === \App\Modules\Boardroom\Domain\ShareEventKind::ReverseSplit ? __('Överskjutande aktier övergår till bolaget (aktiebolagslagen 4 kap. 50 §).') : null">
                        <input id="event-ratio" type="number" min="2" wire:model="form.ratio" class="{{ $input }}" required>
                    </x-ui.field>
                @endif

                @if($kind->refersToEvent())
                    <x-ui.field for="event-refers" :label="__('Gäller händelse')" error="form.refers_to_event_id" class="sm:col-span-2">
                        <select id="event-refers" wire:model="form.refers_to_event_id" class="{{ $input }}" required>
                            <option value="">{{ __('Välj') }}</option>
                            @foreach($events as $event)
                                @if($event->kind !== \App\Modules\Boardroom\Domain\ShareEventKind::Correction && ! in_array($event->id, $voided, true))
                                    <option value="{{ $event->id }}">{{ $event->sequence }}. {{ $event->kind->label() }} {{ $event->effective_date->toDateString() }} {{ $event->rangeLabel() }}</option>
                                @endif
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif

                @if($kind->isNote())
                    <x-ui.field for="event-note" :label="__('Anteckning')" error="form.note" class="sm:col-span-2">
                        <textarea id="event-note" wire:model="form.note" rows="2" class="{{ $input }}" required></textarea>
                    </x-ui.field>
                @endif

                @if($kind === \App\Modules\Boardroom\Domain\ShareEventKind::Correction)
                    <x-ui.field for="event-reason" :label="__('Skäl')" error="form.reason" class="sm:col-span-2" :hint="__('Rättelsen gör den valda händelsen ogiltig. Den finns kvar i historiken. För sedan in den rätta händelsen.')">
                        <textarea id="event-reason" wire:model="form.reason" rows="2" class="{{ $input }}" required></textarea>
                    </x-ui.field>
                @endif

                <x-ui.field for="event-document" :label="__('Underlag')" error="form.document_reference" class="sm:col-span-2" :hint="__('Till exempel avtal, stämmoprotokoll eller Bolagsverkets beslut.')">
                    <input id="event-document" wire:model="form.document_reference" class="{{ $input }}">
                </x-ui.field>

                <x-input-error for="form.event" class="sm:col-span-2" />

                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-button type="submit" wire:confirm="{{ __('För in händelsen i aktieboken? Den kan inte ändras efteråt, bara rättas med en ny händelse.') }}">{{ __('För in i aktieboken') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($events === [])
        <x-ui.empty-state :title="__('Inga händelser ännu')">{{ __('Aktiebokens historik börjar med bildandet, eller med aktieboken som den ser ut när den flyttas till NONOX.') }}</x-ui.empty-state>
    @else
        <x-ui.card :title="__('Historik')">
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Nr') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Händelse') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Gäller från') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Aktier') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Från') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Till') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Införd') }}</th>
                            <th scope="col" class="py-2 font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($events as $event)
                            @php($isVoided = in_array($event->id, $voided, true))
                            <tr @class(['text-gray-500 line-through' => $isVoided])>
                                <td class="py-2 pr-4 tabular-nums">{{ $event->sequence }}</td>
                                <td class="py-2 pr-4">
                                    {{ $event->kind->label() }}
                                    @if($event->refers_to_event_id !== null)
                                        <span class="text-gray-600">({{ __('gäller nr :number', ['number' => $bySequence->get($event->refers_to_event_id)?->sequence]) }})</span>
                                    @endif
                                    @if($event->reason)<p class="text-gray-600 no-underline">{{ $event->reason }}</p>@endif
                                    @if($event->note)<p class="text-gray-600">{{ $event->note }}</p>@endif
                                </td>
                                <td class="py-2 pr-4 tabular-nums">{{ $event->effective_date->toDateString() }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $event->rangeLabel() }}@if($event->ratio) {{ __('kvot :ratio', ['ratio' => $event->ratio]) }}@endif</td>
                                <td class="py-2 pr-4">{{ $holders->get($event->from_holder_id)?->name }}</td>
                                <td class="py-2 pr-4">{{ $event->to_holder_id === null ? '' : ($event->holder_snapshot['name'] ?? $holders->get($event->to_holder_id)?->name) }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ $event->recorded_at->format('Y-m-d H:i') }} · {{ $users->get($event->user_id) ?? '–' }}</td>
                                <td class="py-2 text-right">
                                    @if($canManage && ! $isVoided && $event->kind !== \App\Modules\Boardroom\Domain\ShareEventKind::Correction)
                                        <button type="button" wire:click="correct({{ $event->id }})" class="text-sm font-semibold text-accent-700 underline">{{ __('Rätta') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>

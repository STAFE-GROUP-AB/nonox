@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-8">
    <x-ui.page-heading :title="$meeting->title" :lead="$meeting->kind->label().' · '.$meeting->meeting_date->toDateString().($meeting->start_time ? ' '.__('kl.').' '.$meeting->start_time : '').($meeting->place ? ' · '.$meeting->place : '')">
        <x-slot:action>
            <a href="{{ route('boardroom.meetings') }}" class="text-sm font-semibold text-accent-700 underline">{{ __('Alla möten') }}</a>
        </x-slot:action>
    </x-ui.page-heading>

    @if($locked)
        <div class="rounded-2xl bg-stone-100 p-4 text-sm text-gray-800 ring-1 ring-stone-200" role="status">{{ __('Protokollet är godkänt i NONOX och mötet är låst. Ett fel rättas med ett nytt protokoll som pekar på det gamla.') }}</div>
    @endif

    {{-- Details --}}
    <x-ui.card :title="__('Mötet')">
        @if($canManage && ! $locked && ! $editing)
            <x-slot:action>
                <div class="flex gap-3 text-sm font-semibold">
                    <button type="button" wire:click="edit" class="text-accent-700 underline">{{ __('Ändra') }}</button>
                    @if($minutes->isEmpty())
                        <button type="button" wire:click="delete" wire:confirm="{{ __('Ta bort mötet med dagordningen? Det går inte att ångra.') }}" class="text-red-800 underline">{{ __('Ta bort') }}</button>
                    @endif
                </div>
            </x-slot:action>
        @endif

        @if($editing)
            <form wire:submit="save" class="mt-4 grid gap-5 sm:grid-cols-2">
                <x-ui.field for="m-title" :label="__('Rubrik')" error="form.title" class="sm:col-span-2">
                    <input id="m-title" wire:model="form.title" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="m-date" :label="__('Datum')" error="form.meeting_date">
                    <input id="m-date" type="date" wire:model="form.meeting_date" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="m-time" :label="__('Klockan')" error="form.start_time">
                    <input id="m-time" type="time" wire:model="form.start_time" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="m-place" :label="__('Plats')" error="form.place" class="sm:col-span-2">
                    <input id="m-place" wire:model="form.place" class="{{ $input }}">
                </x-ui.field>
                <label class="flex items-center gap-3 sm:col-span-2"><x-checkbox wire:model="form.digital" /> <span>{{ __('Det går att delta digitalt') }}</span></label>
                @if($meeting->kind->isGeneralMeeting())
                    <x-ui.field for="m-notice" :label="__('Kallelsen skickades')" error="form.notice_sent_on">
                        <input id="m-notice" type="date" wire:model="form.notice_sent_on" class="{{ $input }}">
                    </x-ui.field>
                @endif
                <x-ui.field for="m-chair" :label="__('Ordförande')" error="form.chair_name">
                    <input id="m-chair" wire:model="form.chair_name" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="m-taker" :label="__('Protokollförare')" error="form.minute_taker_name">
                    <input id="m-taker" wire:model="form.minute_taker_name" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="m-adjusters" :label="__('Justerare')" error="form.adjusters" :hint="__('Skilj flera namn med kommatecken.')">
                    <input id="m-adjusters" wire:model="form.adjusters" class="{{ $input }}">
                </x-ui.field>

                <fieldset class="sm:col-span-2">
                    <legend class="text-sm font-medium text-gray-700">{{ __('Närvarande') }}</legend>
                    <div class="mt-2 space-y-3">
                        @foreach($form['attendees'] ?? [] as $index => $attendee)
                            <div class="grid gap-2 sm:grid-cols-4" wire:key="attendee-{{ $index }}">
                                <input wire:model="form.attendees.{{ $index }}.name" class="{{ $input }}" aria-label="{{ __('Namn') }}" placeholder="{{ __('Namn') }}" required>
                                <select wire:model="form.attendees.{{ $index }}.role" class="{{ $input }}" aria-label="{{ __('Roll') }}">
                                    @foreach($roles as $role)
                                        <option value="{{ $role }}">{{ \App\Modules\Boardroom\Models\Meeting::roleLabel($role) }}</option>
                                    @endforeach
                                </select>
                                <select wire:model="form.attendees.{{ $index }}.shareholder_id" class="{{ $input }}" aria-label="{{ __('Företräder aktieägaren') }}">
                                    <option value="">{{ __('Ingen aktieägare') }}</option>
                                    @foreach($holders as $holder)
                                        <option value="{{ $holder->id }}">{{ $holder->name }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="removeAttendee({{ $index }})" class="text-left text-sm font-semibold text-red-800 underline">{{ __('Ta bort') }}</button>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addAttendee" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till närvarande') }}</button>
                    </div>
                </fieldset>

                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-button type="submit">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('editing', false)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        @else
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-600">{{ __('Ordförande') }}</dt><dd class="text-ink">{{ $meeting->chair_name ?: '–' }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Protokollförare') }}</dt><dd class="text-ink">{{ $meeting->minute_taker_name ?: '–' }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Justerare') }}</dt><dd class="text-ink">{{ empty($meeting->adjusters) ? '–' : implode(', ', $meeting->adjusters) }}</dd></div>
                <div class="sm:col-span-3"><dt class="text-gray-600">{{ __('Närvarande') }}</dt><dd class="text-ink">{{ collect($meeting->attendees ?? [])->map(fn ($a) => $a['name'].' ('.\App\Modules\Boardroom\Models\Meeting::roleLabel($a['role']).')')->join(', ') ?: '–' }}</dd></div>
            </dl>
        @endif
    </x-ui.card>

    {{-- Notice advice --}}
    @if($window !== null)
        <x-ui.card :title="__('Kallelse')" :description="__('Råd från NONOX. Kontrollera mot aktiebolagslagen och bolagsordningen.')">
            <div class="mt-4 space-y-2 text-sm text-gray-800">
                <p>{{ __('Skicka kallelsen tidigast :earliest och senast :latest (:source).', ['earliest' => $window->earliest->toDateString(), 'latest' => $window->latest->toDateString(), 'source' => $window->source]) }}
                    @unless($window->verified)<span class="font-semibold">{{ __('kontrollera mot aktiebolagslagen') }}</span>@endunless</p>
                <p>{{ __('En aktieägare som vill ta upp ett ärende ska begära det skriftligen senast :date.', ['date' => $window->itemRequestDeadline()->toDateString()]) }}</p>
                <p>{{ __('Så kallas aktieägarna enligt bolagsordningen') }}: {{ $window->method ?? __('ingen bolagsordning är införd') }}</p>
                @if($window->postRequired)
                    <p class="font-semibold text-amber-900">{{ __('Stämman ska besluta om en ändring av bolagsordningen. Kallelsen kan då behöva skickas med post till varje aktieägare med känd adress (aktiebolagslagen 7 kap. 23 §).') }}</p>
                @endif
                @if($meeting->notice_sent_on)
                    @php($judged = $window->judge($meeting->notice_sent_on))
                    <p>{{ __('Kallelsen skickades :date.', ['date' => $meeting->notice_sent_on->toDateString()]) }}
                        @if($judged)<span class="font-semibold text-red-800">{{ __('Det verkar vara :judgement – kontrollera mot aktiebolagslagen.', ['judgement' => $judged]) }}</span>@endif</p>
                @endif
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('boardroom.meetings.notice', $meeting) }}" target="_blank" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold text-accent-700 ring-1 ring-accent-300 hover:bg-accent-50">{{ __('Skriv ut kallelsen') }}</a>
                <a href="{{ route('share-register.print', ['datum' => $meeting->meeting_date->toDateString(), 'version' => 'fullstandig']) }}" target="_blank" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold text-accent-700 ring-1 ring-accent-300 hover:bg-accent-50">{{ __('Aktieboken per stämmodagen') }}</a>
            </div>
            <p class="mt-3 text-sm text-gray-600">{{ __('NONOX skickar inte kallelsen. Skicka den på det sätt bolagsordningen anger.') }}</p>
        </x-ui.card>
    @endif

    {{-- Agenda --}}
    <x-ui.card :title="__('Dagordning')" :description="__('Ärendena är numrerade. Numreringen följer med till protokollet.')">
        <ol class="mt-4 divide-y divide-stone-200">
            @foreach($items as $item)
                <li class="py-3 text-sm" wire:key="item-{{ $item->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $item->position }}. {{ $item->title }}
                                @if($item->mandatory)<x-ui.badge>{{ __('Obligatoriskt på årsstämman') }}</x-ui.badge>@endif
                            </p>
                            @if($item->proposal)<p class="text-gray-700">{{ __('Förslag') }}: {{ $item->proposal }}</p>@endif
                            @if($item->decision)<p class="text-gray-700">{{ __('Beslut') }}: {{ $item->decision }}</p>@endif
                            @if($item->reportable)
                                <p class="mt-1 text-gray-800">
                                    <span class="font-semibold">{{ __('Anmäls till Bolagsverket') }}: {{ $item->reportable->label() }}.</span>
                                    {{ $item->reportable->effect() }}
                                    @if($item->reported_on)
                                        {{ __('Anmälan kom in :date.', ['date' => $item->reported_on->toDateString()]) }}
                                    @endif
                                </p>
                                @if(! $item->reported_on && $canManage)
                                    <form wire:submit="recordReported({{ $item->id }})" class="mt-2 flex flex-wrap items-end gap-3">
                                        <x-ui.field for="reported-{{ $item->id }}" :label="__('Anmälan inkom till Bolagsverket')" error="reported.reported_on">
                                            <input id="reported-{{ $item->id }}" type="date" wire:model="reported.{{ $item->id }}" class="{{ $input }}" required>
                                        </x-ui.field>
                                        <x-button type="submit">{{ __('Spara') }}</x-button>
                                        <a href="{{ \App\Modules\Boardroom\Support\BoardroomDeadlines::E_SERVICE }}" target="_blank" rel="noopener" class="text-sm font-semibold text-accent-700 underline">{{ __('Bolagsverkets e-tjänst') }}</a>
                                    </form>
                                @endif
                            @endif
                        </div>
                        @if($canManage && ! $locked)
                            <div class="flex gap-3 font-semibold">
                                <button type="button" wire:click="editItem({{ $item->id }})" class="text-accent-700 underline">{{ __('Ändra') }}</button>
                                @unless($item->mandatory)
                                    <button type="button" wire:click="removeItem({{ $item->id }})" wire:confirm="{{ __('Ta bort ärendet från dagordningen?') }}" class="text-red-800 underline">{{ __('Ta bort') }}</button>
                                @endunless
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>

        @if($canManage && ! $locked && $editingItem === null)
            <button type="button" wire:click="editItem" class="mt-4 text-sm font-semibold text-accent-700 underline">{{ __('Lägg till ärende') }}</button>
        @endif

        @if($editingItem !== null)
            <form wire:submit="saveItem" class="mt-4 grid gap-5 sm:grid-cols-2">
                <x-ui.field for="item-kind" :label="__('Typ av ärende')" error="item.kind">
                    <select id="item-kind" wire:model="item.kind" class="{{ $input }}">
                        @foreach($itemKinds as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="item-report" :label="__('Ska anmälas till Bolagsverket')" error="item.reportable" :hint="__('Val av styrelse, revisor och ändrad bolagsordning föreslås automatiskt.')">
                    <select id="item-report" wire:model="item.reportable" class="{{ $input }}">
                        <option value="">{{ __('Enligt typ av ärende') }}</option>
                        @foreach($reportables as $reportable)
                            <option value="{{ $reportable->value }}">{{ $reportable->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="item-title" :label="__('Ärende')" error="item.title" class="sm:col-span-2">
                    <input id="item-title" wire:model="item.title" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="item-proposal" :label="__('Förslag')" error="item.proposal" class="sm:col-span-2">
                    <textarea id="item-proposal" wire:model="item.proposal" rows="2" class="{{ $input }}"></textarea>
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-button type="submit">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('editingItem', null)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        @endif
    </x-ui.card>

    {{-- Voting list --}}
    @if($meeting->kind->isGeneralMeeting() && $votingList !== [])
        <x-ui.card :title="__('Röstlängd')" :description="__('Från aktieboken på stämmodagen. Rätt att delta har den som är införd i aktieboken den dagen.')">
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead><tr class="text-left text-gray-600"><th scope="col" class="py-2 pr-4 font-medium">{{ __('Namn') }}</th><th scope="col" class="py-2 pr-4 font-medium">{{ __('Roll') }}</th><th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Aktier') }}</th><th scope="col" class="py-2 text-right font-medium">{{ __('Röster') }}</th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($votingList as $row)
                            <tr><td class="py-2 pr-4">{{ $row['name'] }}</td><td class="py-2 pr-4">{{ \App\Modules\Boardroom\Models\Meeting::roleLabel($row['role']) }}</td><td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['shares'], 0, ',', ' ') }}</td><td class="py-2 text-right tabular-nums">{{ number_format($row['votes_tenths'] / 10, $row['votes_tenths'] % 10 === 0 ? 0 : 1, ',', ' ') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    {{-- Minutes --}}
    <x-ui.card :title="__('Protokoll')" :description="__('Utkast, justerat och sedan godkänt i NONOX med vem och när. Godkännandet i NONOX är inte en underskrift.')">
        @foreach($minutes->filter(fn ($m) => $m->isApproved()) as $approved)
            <div class="mt-4 rounded-2xl bg-green-50 p-4 text-sm text-green-900 ring-1 ring-green-200">
                <p class="font-semibold">
                    {{ __('Godkänt i NONOX av :name :time', ['name' => $approvers->get($approved->approved_by) ?? '–', 'time' => $approved->approved_at?->format('Y-m-d H:i')]) }}
                    @if($approved->number !== null) · {{ __('protokoll nr :number', ['number' => $approved->number]) }} @endif
                    @if($approved->corrects_minutes_id !== null) · {{ __('rättar ett tidigare protokoll') }} @endif
                </p>
                <p class="mt-1 break-all text-green-800">{{ __('Kontrollsumma (SHA-256)') }}: {{ $approved->sha256 }}</p>
                <div class="mt-2 flex flex-wrap gap-4">
                    <a href="{{ route('boardroom.minutes.pdf', $approved->id) }}" target="_blank" class="font-semibold underline">{{ __('Öppna protokollet') }}</a>
                    @if($canManage && $draft === null && $loop->last)
                        <button type="button" wire:click="startMinutes({{ $approved->id }})" wire:confirm="{{ __('Rätta protokollet? Ett nytt protokoll skapas som pekar på det godkända. Det godkända finns kvar.') }}" class="font-semibold underline">{{ __('Rätta protokollet') }}</button>
                    @endif
                </div>
            </div>
        @endforeach

        @if($draft !== null)
            <div class="mt-4 space-y-5">
                <p class="text-sm text-gray-700"><x-ui.badge>{{ $draft->status->label() }}</x-ui.badge>
                    @if($draft->corrects_minutes_id !== null) {{ __('Det här protokollet rättar ett godkänt protokoll.') }} @endif</p>
                @foreach($draft->body['items'] ?? [] as $row)
                    <div wire:key="minutes-item-{{ $row['position'] }}">
                        <x-ui.field for="decision-{{ $row['position'] }}" :label="$row['position'].'. '.$row['title']" error="minutesForm.decisions">
                            <textarea id="decision-{{ $row['position'] }}" wire:model="minutesForm.decisions.{{ $row['position'] }}" rows="2" class="{{ $input }}" placeholder="{{ __('Beslut') }}" @disabled(! $canManage)></textarea>
                        </x-ui.field>
                        @if($meeting->kind === \App\Modules\Boardroom\Domain\MeetingKind::BoardMeeting)
                            <x-ui.field for="dissent-{{ $row['position'] }}" :label="__('Reservation')" class="mt-2">
                                <input id="dissent-{{ $row['position'] }}" wire:model="minutesForm.dissents.{{ $row['position'] }}" class="{{ $input }}" @disabled(! $canManage)>
                            </x-ui.field>
                        @endif
                    </div>
                @endforeach
                <x-ui.field for="minutes-notes" :label="__('Övrigt')" error="minutesForm.notes" :hint="$meeting->kind === \App\Modules\Boardroom\Domain\MeetingKind::BoardMeeting ? __('I ett bolag med en enda aktieägare antecknas här avtal mellan ägaren och bolaget som inte är vanliga affärer (aktiebolagslagen 8 kap. 25 §).') : null">
                    <textarea id="minutes-notes" wire:model="minutesForm.notes" rows="3" class="{{ $input }}" @disabled(! $canManage)></textarea>
                </x-ui.field>

                @if($canManage)
                    <div class="flex flex-wrap gap-3">
                        <x-secondary-button type="button" wire:click="saveMinutes">{{ __('Spara utkastet') }}</x-secondary-button>
                        <button type="button" wire:click="discard" wire:confirm="{{ __('Ta bort utkastet till protokoll?') }}" class="text-sm font-semibold text-red-800 underline">{{ __('Ta bort utkastet') }}</button>
                    </div>

                    @if($draft->status === \App\Modules\Boardroom\Domain\MinutesStatus::Draft)
                        <form wire:submit="check" class="grid gap-5 rounded-2xl bg-paper/60 p-4 ring-1 ring-stone-200 sm:grid-cols-3">
                            <p class="text-sm text-gray-700 sm:col-span-3">{{ $meeting->kind->isGeneralMeeting() ? __('Protokollet justeras av ordföranden och minst en justerare som stämman utsett, om inte ordföranden och protokollföraren ensamma företräder alla aktier (aktiebolagslagen 7 kap. 48 §).') : __('Protokollet justeras av ordföranden och, om styrelsen har flera ledamöter, av en ledamot som styrelsen utsett (aktiebolagslagen 8 kap. 24 §).') }}</p>
                            <x-ui.field for="check-chair" :label="__('Ordförande')" error="checking.chair">
                                <input id="check-chair" wire:model="checking.chair" class="{{ $input }}" required>
                            </x-ui.field>
                            <x-ui.field for="check-taker" :label="__('Protokollförare')" error="checking.minute_taker">
                                <input id="check-taker" wire:model="checking.minute_taker" class="{{ $input }}" required>
                            </x-ui.field>
                            <x-ui.field for="check-adjusters" :label="__('Justerare')" error="checking.adjusters" :hint="__('Skilj flera namn med kommatecken.')">
                                <input id="check-adjusters" wire:model="checking.adjusters" class="{{ $input }}">
                            </x-ui.field>
                            <div class="sm:col-span-3"><x-button type="submit">{{ __('Markera som justerat') }}</x-button></div>
                        </form>
                    @else
                        <div class="rounded-2xl bg-paper/60 p-4 text-sm ring-1 ring-stone-200">
                            <p class="text-gray-800">{{ __('Justerat av :names :time.', ['names' => implode(', ', (array) $draft->checked_names), 'time' => $draft->checked_at?->format('Y-m-d H:i')]) }}</p>
                            <p class="mt-2 text-gray-700">{{ __('När du godkänner låses protokollet och sparas som PDF med kontrollsumma. Godkännandet visar vem och när; det är inte en underskrift.') }}</p>
                            <x-button type="button" class="mt-3" wire:click="approve" wire:confirm="{{ __('Godkänna protokollet i NONOX? Det låses och kan sedan bara rättas med ett nytt protokoll.') }}">{{ __('Godkänn i NONOX') }}</x-button>
                        </div>
                    @endif
                @endif
            </div>
        @elseif($minutes->isEmpty())
            @if($canManage)
                <button type="button" wire:click="startMinutes" class="mt-4 inline-flex items-center rounded-lg bg-accent-700 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-800">{{ __('Skriv protokoll') }}</button>
            @else
                <p class="mt-4 text-sm text-gray-700">{{ __('Inget protokoll ännu.') }}</p>
            @endif
        @endif
    </x-ui.card>
</div>

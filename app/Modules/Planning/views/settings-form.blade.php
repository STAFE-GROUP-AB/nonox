@php($input = 'mt-1 block border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs tabular-nums')
<form wire:submit="save" class="space-y-6">
    <div class="grid gap-5 sm:grid-cols-2">
        <x-ui.field for="pl-view" :label="__('Schemat öppnas i')" error="settings.default_view">
            <select id="pl-view" wire:model="settings.default_view" class="{{ $input }} w-40">
                <option value="week">{{ __('Veckovy') }}</option>
                <option value="day">{{ __('Dagvy') }}</option>
            </select>
        </x-ui.field>
        <x-ui.field for="pl-step" :label="__('Tidssteg när du drar (minuter)')" error="settings.time_step_minutes">
            <select id="pl-step" wire:model="settings.time_step_minutes" class="{{ $input }} w-28">
                @foreach(App\Modules\Planning\PlanningSettings::TIME_STEPS as $step)
                    <option value="{{ $step }}">{{ $step }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <x-ui.field for="pl-ahead" :label="__('Publicera i förväg (dagar)')" :hint="__('Bara en påminnelse i Att göra. Inget publiceras automatiskt.')" error="settings.publish_ahead_days">
            <input id="pl-ahead" type="number" min="0" max="90" wire:model="settings.publish_ahead_days" class="{{ $input }} w-28">
        </x-ui.field>
        <div class="space-y-2">
            <p class="text-sm font-medium text-gray-700">{{ __('Aviseringar till personalen') }}</p>
            <label class="flex items-center gap-3"><x-checkbox wire:model="settings.notify_on_publish" /> <span>{{ __('När schemat publiceras, med nya och ändrade pass') }}</span></label>
            <label class="flex items-center gap-3"><x-checkbox wire:model="settings.notify_on_change" /> <span>{{ __('Direkt när ett publicerat pass ställs in') }}</span></label>
            <p class="text-sm text-gray-500">{{ __('En ändring i ett publicerat pass skickas när du publicerar igen.') }}</p>
        </div>
        <div class="space-y-2 sm:col-span-2">
            <p class="text-sm font-medium text-gray-700">{{ __('Personalens egna val i Min sida') }}</p>
            <label class="flex items-center gap-3"><x-checkbox wire:model="settings.allow_open_shift_claims" /> <span>{{ __('Får ta öppna pass') }}</span></label>
            <label class="flex items-center gap-3"><x-checkbox wire:model="settings.allow_swaps" /> <span>{{ __('Får be om att byta pass (en chef godkänner alltid)') }}</span></label>
            <label class="flex items-center gap-3"><x-checkbox wire:model="settings.allow_availability" /> <span>{{ __('Får ange när de kan arbeta') }}</span></label>
        </div>
    </div>

    <fieldset class="space-y-3">
        <legend class="text-sm font-bold text-ink">{{ __('Arbetstidsregler') }}</legend>
        <p class="text-sm text-gray-600">{{ __('Standardvärden ur arbetstidslagen. Ett kollektivavtal kan ge andra värden, så ändra dem efter ert avtal. Reglerna varnar i schemat men stoppar aldrig ett pass. Värdena är inte kontrollerade av en jurist ännu.') }}</p>
        <div class="divide-y divide-stone-200 rounded-2xl ring-1 ring-stone-200">
            @foreach($settings['working_time_rules'] as $key => $rule)
                <div wire:key="wtr-{{ $key }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    @if(($rule['checked'] ?? true) === false)
                        {{-- Kept as data for a later check against worked time; no switch, so it cannot turn another check off. --}}
                        <span class="min-w-0 text-sm"><span class="font-medium text-ink">{{ $labels[$key] }}</span>
                            <span class="block text-xs text-gray-500">{{ $rule['source'] }} · <span class="font-semibold text-amber-800">{{ __('Kontrolleras inte i schemat ännu') }}</span></span></span>
                    @else
                        <label class="flex min-w-0 items-center gap-3">
                            <x-checkbox wire:model="settings.working_time_rules.{{ $key }}.enabled" />
                            <span class="text-sm"><span class="font-medium text-ink">{{ $labels[$key] }}</span> <span class="block text-xs text-gray-500">{{ $rule['source'] }}</span></span>
                        </label>
                    @endif
                    @if($rule['unit'] !== 'none')
                        <input type="number" min="0" wire:model="settings.working_time_rules.{{ $key }}.value" class="{{ $input }} mt-0 w-28" aria-label="{{ $labels[$key] }}">
                    @endif
                </div>
            @endforeach
        </div>
        <x-input-error for="settings.working_time_rules" class="mt-2" />
    </fieldset>

    <fieldset class="space-y-3">
        <legend class="text-sm font-bold text-ink">{{ __('Minderåriga') }}</legend>
        <label class="flex items-center gap-3"><x-checkbox wire:model.live="settings.minors_enabled" /> <span>{{ __('Varna för pass som inte passar anställda under 18 år (åldern räknas från personnumret)') }}</span></label>
        @if($settings['minors_enabled'])
            <p class="text-sm text-gray-600">{{ __('Gränserna är data att kontrollera mot Arbetsmiljöverkets föreskrifter om minderårigas arbetsmiljö och ert avtal. De varnar, men stoppar inget.') }}</p>
            <div class="divide-y divide-stone-200 rounded-2xl ring-1 ring-stone-200">
                @foreach($settings['minors_rules'] as $key => $rule)
                    <div wire:key="mr-{{ $key }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <label class="flex min-w-0 items-center gap-3">
                            <x-checkbox wire:model="settings.minors_rules.{{ $key }}.enabled" />
                            <span class="text-sm"><span class="font-medium text-ink">{{ $labels[$key] }}</span> <span class="block text-xs text-gray-500">{{ $rule['source'] }}</span></span>
                        </label>
                        <input type="{{ $rule['unit'] === 'time' ? 'time' : 'number' }}" min="0" wire:model="settings.minors_rules.{{ $key }}.value" class="{{ $input }} mt-0 w-28" aria-label="{{ $labels[$key] }}">
                    </div>
                @endforeach
            </div>
        @endif
    </fieldset>

    <fieldset class="grid gap-5 sm:grid-cols-2" @disabled(! $canAttest)>
        <legend class="text-sm font-bold text-ink sm:col-span-2">{{ __('Attest och lön') }}</legend>
        @unless($canAttest)
            <p class="text-sm text-gray-600 sm:col-span-2">{{ __('Bara den som attesterar tid till lönen kan ändra de här.') }}</p>
        @endunless
        <x-ui.field for="pl-tolerance" :label="__('Tolerans vid attest (minuter)')" :hint="__('Skillnader mellan schema och personalliggare under gränsen godkänns utan avvikelse.')" error="settings.attest_tolerance_minutes">
            <input id="pl-tolerance" type="number" min="0" max="60" wire:model="settings.attest_tolerance_minutes" class="{{ $input }} w-28">
        </x-ui.field>
        <x-ui.field for="pl-rounding" :label="__('Avrunda in- och utcheckning (minuter)')" :hint="__('0 betyder ingen avrundning.')" error="settings.check_in_rounding_minutes">
            <input id="pl-rounding" type="number" min="0" max="30" wire:model="settings.check_in_rounding_minutes" class="{{ $input }} w-28">
        </x-ui.field>
        @foreach(['overtime_pay_code' => [__('Löneart för övertid (månadslön, heltid)'), __('Tid utöver veckoarbetstiden räknas per kalendervecka av attesterade dagar och skickas på lönearten när hela veckan är attesterad. Utan löneart visas den som avvikelse och läggs in i lönekörningen för hand.')], 'extra_time_pay_code' => [__('Löneart för mertid (månadslön, deltid)'), __('Mertid är tiden upp till heltid; tid därutöver är övertid. För Gröna Riks och Detaljhandeln delas tiden upp per övertidsart på avtalets lönearter när fältet är tomt eller har en av avtalets lönearter.')]] as $key => [$label, $hint])
            <x-ui.field for="pl-{{ $key }}" :label="$label" :hint="$hint" error="settings.{{ $key }}">
                <select id="pl-{{ $key }}" wire:model="settings.{{ $key }}" class="{{ $input }}">
                    <option value="">{{ __('Skicka inte, visa som avvikelse') }}</option>
                    @foreach($payCodes as $code => $name)
                        <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                    @endforeach
                </select>
            </x-ui.field>
        @endforeach
        <x-ui.field for="pl-period-weeks" :label="__('Beräkningsperiod för övertid och mertid (veckor)')" :hint="__('1 betyder per kalendervecka. Med ett rullande schema väljer du schemats längd: tiden jämförs med veckoarbetstiden gånger antalet veckor. Avtalet anger hur lång perioden får vara.')" error="settings.overtime_period_weeks">
            <input id="pl-period-weeks" type="number" min="1" max="52" wire:model="settings.overtime_period_weeks" class="{{ $input }} w-28">
        </x-ui.field>
        <x-ui.field for="pl-period-start" :label="__('Första periodens måndag')" :hint="__('Perioderna räknas från den här måndagen. Tomt betyder 1 januari 2024.')" error="settings.overtime_period_start">
            <input id="pl-period-start" type="date" wire:model="settings.overtime_period_start" class="{{ $input }} w-44">
        </x-ui.field>
        <x-ui.field for="pl-comp-factor" :label="__('Komptid per timme övertid eller mertid')" :hint="__('Gäller när avtalet inte anger någon faktor för lönearten. 1 betyder timme mot timme. Det här är företagets egen regel.')" error="settings.comp_time_factor">
            <input id="pl-comp-factor" type="text" inputmode="decimal" wire:model="settings.comp_time_factor" class="{{ $input }} w-28">
        </x-ui.field>
    </fieldset>

    <div class="grid gap-5 sm:grid-cols-2">
        <x-ui.field for="pl-sales-from" :label="__('Försäljning från konto')" error="settings.sales_account_from">
            <input id="pl-sales-from" type="number" min="1000" max="9999" wire:model="settings.sales_account_from" class="{{ $input }} w-28">
        </x-ui.field>
        <x-ui.field for="pl-sales-to" :label="__('till och med konto')" :hint="__('Bokförda intäkter på kontona räknas som dagens försäljning.')" error="settings.sales_account_to">
            <input id="pl-sales-to" type="number" min="1000" max="9999" wire:model="settings.sales_account_to" class="{{ $input }} w-28">
        </x-ui.field>
    </div>

    <x-button type="submit">{{ __('Spara') }}</x-button>
</form>

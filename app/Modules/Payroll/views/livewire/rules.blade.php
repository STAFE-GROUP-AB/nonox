<div class="space-y-6">
    @php($ready = $readiness['counts'][App\Modules\Payroll\Support\YearReadiness::VERIFIED] === count($readiness['rows']) && $readiness['tax_tables'] !== null)
    <x-ui.card>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ __('Inför löneåret :year', ['year' => $readiness['year']]) }}</h2>
                <p class="mt-1 max-w-3xl text-sm text-gray-600">{{ __('En lönekörning använder regler och skattetabeller för året då lönen betalas ut. Lön för december som betalas i januari räknas alltså med det nya årets värden.') }}</p>
            </div>
            <x-ui.field for="rules_year" :label="__('Utbetalningsår')">
                <select id="rules_year" class="block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs mt-1" wire:model.live="year">
                    @foreach($years as $option)
                        <option value="{{ $option }}" @selected($option === $readiness['year'])>{{ $option }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        </div>

        <p @class(['mt-4 text-sm font-medium', 'text-gray-900' => $ready, 'text-accent-700' => ! $ready])>
            {{ $ready
                ? __('Allt är klart för lönekörningar med utbetalning :year.', ['year' => $readiness['year']])
                : __(':verified av :total regler är kontrollerade för :year. :unverified väntar på kontroll, :pending är inte publicerade ännu och :missing saknas.', [
                    'verified' => $readiness['counts']['verified'], 'total' => count($readiness['rows']), 'year' => $readiness['year'],
                    'unverified' => $readiness['counts']['unverified'], 'pending' => $readiness['counts']['pending'], 'missing' => $readiness['counts']['missing'],
                ]) }}
        </p>

        <div class="mt-4 rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('Skattetabeller :year', ['year' => $readiness['year']]) }}</h3>
            @if($readiness['tax_tables'] !== null)
                <p class="mt-1 text-sm text-gray-700">
                    <x-ui.badge tone="success">{{ __('Inlästa') }}</x-ui.badge>
                    {{ __(':count tabeller från :file, inlästa :date.', ['count' => $readiness['tax_tables']->table_count, 'file' => $readiness['tax_tables']->file_name, 'date' => Carbon\CarbonImmutable::parse($readiness['tax_tables']->created_at)->setTimezone(config('app.timezone'))->format('Y-m-d')]) }}
                </p>
            @else
                <p class="mt-1 text-sm text-gray-700">
                    <x-ui.badge tone="accent">{{ __('Inte inlästa ännu') }}</x-ui.badge>
                    {{ __('Skattetabellerna för :year är inte inlästa ännu. Skatteverket publicerar dem i december året före. Utan dem stoppas varje lönekörning med utbetalning :year för anställda som betalar skatt enligt tabell.', ['year' => $readiness['year']]) }}
                </p>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __('Tabellerna gäller alla företag i installationen. NONOX hämtar dem själv från Skatteverket varje natt så snart de är publicerade, och du får en notis när de är inlästa. Den som driftar NONOX kan också läsa in dem direkt med kommandot') }}
                    <code class="rounded-sm bg-gray-100 px-1 text-xs">php artisan payroll:import-tax-tables {{ $readiness['year'] }} --download</code>.
                    <a href="https://www.skatteverket.se/foretag/arbetsgivare/arbetsgivaravgifterochskatteavdrag/skattetabeller.4.96cca41179bad4b1aa8a46.html" target="_blank" rel="noopener" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Skattetabellerna hos Skatteverket') }}</a>
                </p>
            @endif
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500">
                        <th class="py-2 pr-4 font-medium">{{ __('Regel') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Läge') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Versioner under året') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($readiness['rows'] as $row)
                        <tr wire:key="year-{{ $row['key'] }}">
                            <td class="py-2 pr-4 align-top">
                                <span class="block font-medium text-gray-900">{{ App\Modules\Payroll\Support\RulePresenter::name($row['key']) }}</span>
                                <span class="block font-mono text-xs text-gray-400">{{ $row['key'] }}</span>
                            </td>
                            <td class="py-2 pr-4 align-top">
                                @switch($row['status'])
                                    @case('verified')
                                        <x-ui.badge tone="success">{{ __('Kontrollerad') }}</x-ui.badge>
                                        @break
                                    @case('unverified')
                                        <x-ui.badge tone="accent">{{ __('Ej kontrollerad') }}</x-ui.badge>
                                        <span class="mt-1 block text-xs text-gray-500">{{ __('Stoppar lönekörningar som behöver regeln.') }}</span>
                                        @break
                                    @case('pending')
                                        <x-ui.badge tone="neutral">{{ __('Inte publicerad ännu') }}</x-ui.badge>
                                        <span class="mt-1 block text-xs text-gray-500">
                                            {{ __('Saknas från :date. :authority väntas publicera värdet :expected.', ['date' => $row['gap_from'], 'authority' => $row['pending']['authority'] ?? __('Myndigheten'), 'expected' => $row['pending']['expected'] ?? '']) }}
                                            @if(! empty($row['pending']['source_url']))
                                                <a href="{{ $row['pending']['source_url'] }}" target="_blank" rel="noopener" class="text-accent-600 hover:text-accent-700">{{ __('Källa') }}</a>
                                            @endif
                                        </span>
                                        @break
                                    @default
                                        <x-ui.badge tone="accent">{{ __('Saknas') }}</x-ui.badge>
                                        <span class="mt-1 block text-xs text-gray-500">{{ __('Saknas från :date. Stoppar lönekörningar som behöver regeln.', ['date' => $row['gap_from']]) }}</span>
                                @endswitch
                            </td>
                            <td class="py-2 pr-4 align-top">
                                <ul class="space-y-1">
                                    @foreach($row['versions'] as $version)
                                        <li>
                                            <span class="tabular-nums whitespace-nowrap">{{ $version->valid_from->toDateString() }} – {{ $version->valid_to?->toDateString() }}</span>
                                            <span class="text-gray-600">{{ implode(' · ', App\Modules\Payroll\Support\RulePresenter::lines($row['key'], $version->value)) }}</span>
                                            <a href="{{ $version->source_url }}" target="_blank" rel="noopener" class="text-xs text-accent-600 hover:text-accent-700">{{ __('Källa') }}</a>
                                            @if($version->isVerified())
                                                <span class="text-xs text-gray-500">{{ __('Kontrollerad av :name', ['name' => $version->verified_by]) }}</span>
                                            @elseif(isset($readiness['checked'][$version->id]))
                                                <span class="text-xs text-gray-500">{{ __('Kontrollerad av :name', ['name' => $readiness['checked'][$version->id]->verifier_name]) }}</span>
                                            @elseif($canVerify && ! ($checking === $version->id && $checkingIn === 'year'))
                                                <button type="button" class="text-xs font-medium text-accent-600 hover:text-accent-700" wire:click="check({{ $version->id }}, 'year')">{{ __('Kontrollera') }}</button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                        @foreach($row['versions'] as $version)
                            @if($checking === $version->id && $checkingIn === 'year')
                                <tr wire:key="year-check-{{ $version->id }}">
                                    @include('payroll::livewire.partials.rule-check', ['version' => $version, 'colspan' => 3])
                                </tr>
                            @endif
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <x-ui.field for="rules_date" :label="__('Gäller den')">
                <input id="rules_date" type="date" class="block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs mt-1" wire:model.live="date" value="{{ $day->toDateString() }}">
            </x-ui.field>
            <p @class(['text-sm font-medium', 'text-accent-700' => $missing !== [], 'text-gray-900' => $missing === []])>
                {{ $missing === []
                    ? __('Alla regler är kontrollerade för :date.', ['date' => $day->toDateString()])
                    : __(':count av :total regler saknas eller är inte kontrollerade för :date. En lönekörning med utbetalning den dagen stoppas tills de är det.', ['count' => count($missing), 'total' => count($rows), 'date' => $day->toDateString()]) }}
            </p>
        </div>
        <p class="mt-3 text-sm text-gray-600">{{ __('Varje sats hämtas från Skatteverket, Försäkringskassan eller lagtexten och används först när en namngiven, behörig person har kontrollerat den mot källan. Ett kontrollerat värde ändras aldrig; en ändring blir en ny version med nytt datum.') }}</p>
    </x-ui.card>

    <x-ui.card>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500">
                        <th class="py-2 pr-4 font-medium">{{ __('Regel') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Värde') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Gäller') }}</th>
                        <th class="py-2 font-medium">{{ __('Kontrollerad') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                        @forelse($row['versions'] as $version)
                            <tr>
                                <td class="py-2 pr-4 align-top">
                                    <span class="block font-medium text-gray-900">{{ App\Modules\Payroll\Support\RulePresenter::name($row['key']) }}</span>
                                    <span class="block font-mono text-xs text-gray-400">{{ $row['key'] }}</span>
                                </td>
                                <td class="py-2 pr-4 align-top">
                                    <ul class="space-y-0.5">
                                        @foreach(App\Modules\Payroll\Support\RulePresenter::lines($row['key'], $version->value) as $line)
                                            <li>{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                    <a href="{{ $version->source_url }}" target="_blank" rel="noopener" class="mt-1 inline-block text-xs text-accent-600 hover:text-accent-700">{{ __('Källa') }}</a>
                                </td>
                                <td class="py-2 pr-4 align-top tabular-nums whitespace-nowrap">{{ $version->valid_from->toDateString() }} – {{ $version->valid_to?->toDateString() }}</td>
                                <td class="py-2">
                                    @if($version->isVerified())
                                        <x-ui.badge tone="success">{{ $version->verified_by }}</x-ui.badge>
                                    @elseif($companyChecks->has($version->id))
                                        <x-ui.badge tone="success">{{ $companyChecks->get($version->id)->verifier_name }}</x-ui.badge>
                                        <span class="block text-xs text-gray-500">{{ $companyChecks->get($version->id)->verified_at->setTimezone(config('app.timezone'))->format('Y-m-d') }}</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-accent-50 px-3 py-1 text-xs font-medium text-accent-700">{{ __('Ej kontrollerad') }}</span>
                                        @if($canVerify && $checking !== $version->id)
                                            <button type="button" class="mt-1 block text-xs font-medium text-accent-600 hover:text-accent-700" wire:click="check({{ $version->id }}, 'day')">{{ __('Kontrollera') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                            @if($checking === $version->id && $checkingIn === 'day')
                                <tr>
                                    @include('payroll::livewire.partials.rule-check', ['version' => $version, 'colspan' => 4])
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td class="py-2 pr-4">
                                    <span class="block font-medium text-gray-900">{{ App\Modules\Payroll\Support\RulePresenter::name($row['key']) }}</span>
                                    <span class="block font-mono text-xs text-gray-400">{{ $row['key'] }}</span>
                                </td>
                                <td class="py-2 pr-4 text-gray-500" colspan="2">{{ __('Saknas') }}</td>
                                <td class="py-2"><span class="inline-flex rounded-full bg-accent-50 px-3 py-1 text-xs font-medium text-accent-700">{{ __('Saknas') }}</span></td>
                            </tr>
                        @endforelse
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>

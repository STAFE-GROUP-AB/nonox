<div class="space-y-6">
    <x-ui.card>
        <p class="text-sm text-gray-600">{{ __('Avtalens värden (minimilöner, OB, övertid och dagtyper) levereras som data med källa till parternas publicerade texter, aldrig som avtalstext. Varje version används i lönen först när en namngiven, behörig person har jämfört den med källorna. En kontrollerad version ändras aldrig; en ändring blir en ny version. NONOX lämnar inga garantier för att värdena stämmer för just ert företag – kontrollen är er.') }}</p>
    </x-ui.card>

    <x-ui.card>
        @if($versions->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Ingen avtalsdata är inläst. Slå av och på modulen, eller kör agreements:import.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Avtal') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Variant') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Gäller') }}</th>
                            <th class="py-2 font-medium">{{ __('Kontrollerad') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($versions as $version)
                            @php($rules = $version->agreementRules())
                            <tr>
                                <td class="py-2 pr-4 align-top">
                                    <button type="button" class="font-medium text-gray-900 underline decoration-gray-300 hover:text-accent-700" wire:click="show({{ $version->id }})" aria-expanded="{{ $openVersion?->id === $version->id ? 'true' : 'false' }}">{{ $rules->name() }}</button>
                                    <span class="block text-xs text-gray-500">{{ implode(' – ', $rules->parties()) }}</span>
                                </td>
                                <td class="py-2 pr-4 align-top">{{ App\Modules\Agreements\Models\AgreementRuleVersion::variantLabel($version->variant) }}</td>
                                <td class="py-2 pr-4 align-top tabular-nums whitespace-nowrap">{{ $version->valid_from->toDateString() }} – {{ $version->valid_to?->toDateString() }}</td>
                                <td class="py-2 align-top">
                                    @if($version->isVerified())
                                        <x-ui.badge tone="success">{{ $version->verified_by }}</x-ui.badge>
                                    @elseif($companyChecks->has($version->id))
                                        <x-ui.badge tone="success">{{ $companyChecks->get($version->id)->verifier_name }}</x-ui.badge>
                                        <span class="block text-xs text-gray-500">{{ $companyChecks->get($version->id)->verified_at->setTimezone(config('app.timezone'))->format('Y-m-d') }}</span>
                                    @elseif($rules->hasUnverifiedValues())
                                        <span class="inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">{{ __('Kan inte kontrolleras') }}</span>
                                        <span class="block text-xs text-gray-500">{{ __('Värden saknar primär källa.') }}</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-accent-50 px-3 py-1 text-xs font-medium text-accent-700">{{ __('Ej kontrollerad') }}</span>
                                        @if($canVerify && $checking !== $version->id)
                                            <button type="button" class="mt-1 block text-xs font-medium text-accent-600 hover:text-accent-700" wire:click="check({{ $version->id }})">{{ __('Kontrollera') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                            @if($checking === $version->id)
                                <tr>
                                    <td colspan="4" class="bg-gray-50 px-4 py-3">
                                        <p class="text-sm text-gray-700">{{ __('Öppna varje källa och jämför varje värde i versionen med sidan som anges. Visa värdena genom att klicka på avtalets namn.') }}</p>
                                        <ul class="mt-2 space-y-1 text-sm">
                                            @foreach($version->sources as $source)
                                                <li><a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="font-medium text-accent-600 hover:text-accent-700">{{ $source['id'] }}: {{ $source['title'] }}</a></li>
                                            @endforeach
                                        </ul>
                                        <label class="mt-3 flex items-start gap-2 text-sm">
                                            <input type="checkbox" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="confirmed">
                                            <span>{{ __('Jag har jämfört värdena med källorna och tar ansvar för att de stämmer för vårt företag.') }}</span>
                                        </label>
                                        <x-input-error for="confirmed" class="mt-1" />
                                        <x-input-error for="version" class="mt-1" />
                                        <div class="mt-3 flex items-center gap-3">
                                            <x-button type="button" wire:click="verify">{{ __('Markera som kontrollerad') }}</x-button>
                                            <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('checking', null)">{{ __('Avbryt') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($openVersion !== null)
        @php($rules = $openVersion->agreementRules())
        <x-ui.card :title="$rules->name().' – '.App\Modules\Agreements\Models\AgreementRuleVersion::variantLabel($openVersion->variant)" :description="__('Version från :date. Varje värde visar sin källa (dokument och sida).', ['date' => $openVersion->valid_from->toDateString()])">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('Minimilöner') }}</h3>
            @php($wages = $rules->minimumWages())
            @if($wages === [])
                <p class="mt-1 text-sm text-gray-600">{{ __('Inga minimilöner i den här versionen.') }}</p>
            @else
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-1 pr-4 font-medium">{{ __('Från') }}</th>
                                <th class="py-1 pr-4 font-medium">{{ __('Lönegrupp') }}</th>
                                <th class="py-1 pr-4 font-medium">{{ __('Villkor') }}</th>
                                <th class="py-1 pr-4 text-right font-medium">{{ __('Per månad') }}</th>
                                <th class="py-1 pr-4 text-right font-medium">{{ __('Per timme') }}</th>
                                <th class="py-1 font-medium">{{ __('Källa') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach((array) ($wages['steps'] ?? []) as $step)
                                @foreach((array) ($step['rows'] ?? []) as $row)
                                    <tr>
                                        <td class="py-1 pr-4 tabular-nums">{{ $step['valid_from'] }}</td>
                                        <td class="py-1 pr-4">{{ $rules->group((string) $row['group'])['name'] ?? $row['group'] }}</td>
                                        <td class="py-1 pr-4 text-gray-600">
                                            {{ collect([
                                                isset($row['age_min']) ? __('från :age år', ['age' => $row['age_min']]) : null,
                                                isset($row['age_max']) ? __('till och med :age år', ['age' => $row['age_max']]) : null,
                                                isset($row['experience_months_min']) ? __(':months mån branschvana', ['months' => $row['experience_months_min']]) : null,
                                                isset($row['tenure_months_min']) ? __(':months mån i företaget', ['months' => $row['tenure_months_min']]) : null,
                                            ])->filter()->implode(', ') }}
                                            @if($row['unverified'] ?? false)<span class="text-accent-700">{{ __('(saknar primär källa)') }}</span>@endif
                                        </td>
                                        <td class="py-1 pr-4 text-right tabular-nums">{{ isset($row['monthly_ore']) ? App\Support\Money\Money::ofOre((int) $row['monthly_ore'])->format(withCurrency: false) : '' }}</td>
                                        <td class="py-1 pr-4 text-right tabular-nums">{{ isset($row['hourly_ore']) ? App\Support\Money\Money::ofOre((int) $row['hourly_ore'])->format(withCurrency: false) : '' }}</td>
                                        <td class="py-1 text-xs text-gray-500">{{ $row['source'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <h3 class="mt-6 text-sm font-semibold text-gray-900">{{ __('OB, övertid och andra tillägg') }}</h3>
            @if($rules->codes() === [])
                <p class="mt-1 text-sm text-gray-600">{{ __('Inga tillägg i den här versionen.') }}</p>
            @else
                <ul class="mt-2 space-y-2 text-sm">
                    @foreach($rules->codes() as $code => $definition)
                        <li>
                            <span class="font-medium text-gray-900">{{ $definition['name'] }}</span> <span class="font-mono text-xs text-gray-400">{{ $code }}</span>
                            <span class="block text-gray-600">{{ $definition['description'] ?? '' }}</span>
                            <span class="block text-gray-700 tabular-nums">
                                @foreach((array) ($definition['rates'] ?? []) as $step)
                                    @php($rate = (array) $step['rate'])
                                    {{ __('från :date:', ['date' => $step['valid_from']]) }}
                                    @switch($rate['type'] ?? '')
                                        @case('ORE_PER_HOUR') {{ __(':amount per timme', ['amount' => App\Support\Money\Money::ofOre((int) $rate['ore'])->format()]) }} @break
                                        @case('PERCENT_OF_HOURLY') {{ ($rate['plus_hourly_wage'] ?? false) ? __('timlön + :percent %', ['percent' => number_format($rate['bp'] / 100, 2, ',', ' ')]) : __(':percent % av timlönen', ['percent' => number_format($rate['bp'] / 100, 2, ',', ' ')]) }} @break
                                        @case('DIVISOR_OF_MONTHLY') {{ __('månadslönen / :divisor per timme', ['divisor' => $rate['divisor']]) }} @break
                                    @endswitch
                                    @if(! $loop->last) · @endif
                                @endforeach
                            </span>
                            <span class="block text-xs text-gray-500">{{ __('Källa: :source', ['source' => $definition['source'] ?? '']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($rules->notes() !== [])
                <h3 class="mt-6 text-sm font-semibold text-gray-900">{{ __('Det här räknar NONOX inte ännu') }}</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700">
                    @foreach($rules->notes() as $note)<li>{{ $note }}</li>@endforeach
                </ul>
            @endif

            <h3 class="mt-6 text-sm font-semibold text-gray-900">{{ __('Källor') }}</h3>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach($openVersion->sources as $source)
                    <li><a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="text-accent-600 hover:text-accent-700">{{ $source['id'] }}: {{ $source['title'] }}</a> <span class="text-gray-500">({{ $source['publisher'] ?? '' }}, {{ __('hämtad :date', ['date' => $source['retrieved_at'] ?? '']) }})</span></li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.card :title="__('Avtalskalender')" :description="__('Helgdagar enligt lag, aftnar och avtalets dagtyper med klockslag, räknade fram för året.')">
            <x-ui.field for="calendar_year" :label="__('År')">
                <input id="calendar_year" type="number" min="2000" max="2100" wire:model.live.debounce.500ms="year" value="{{ $calendarYear }}" class="mt-1 block w-28 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs tabular-nums">
            </x-ui.field>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-1 pr-4 font-medium">{{ __('Datum') }}</th>
                            <th class="py-1 pr-4 font-medium">{{ __('Dag') }}</th>
                            <th class="py-1 font-medium">{{ __('Enligt avtalet') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($calendar as $day)
                            <tr>
                                <td class="py-1 pr-4 tabular-nums whitespace-nowrap">{{ $day['date'] }} {{ Carbon\CarbonImmutable::parse($day['date'])->translatedFormat('D') }}</td>
                                <td class="py-1 pr-4">{{ collect($day['names'])->map(fn ($name) => App\Modules\Agreements\Domain\DayCalendar::label($name))->implode(', ') }}</td>
                                <td class="py-1 text-gray-700">
                                    @foreach($day['spans'] as $span)
                                        <span class="block">{{ $span['name'] }}: {{ $span['start']->format('H:i') }}–{{ $span['end']->format($span['end']->isSameDay($span['start']) ? 'H:i' : 'Y-m-d H:i') }}</span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>

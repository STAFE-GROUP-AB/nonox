<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <x-ui.field for="rules_date" :label="__('Gäller den')">
                <input id="rules_date" type="date" class="block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm mt-1" wire:model.live="date" value="{{ $day->toDateString() }}">
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
                                <td class="py-2 pr-4 font-mono text-xs">{{ $row['key'] }}</td>
                                <td class="py-2 pr-4 font-mono text-xs break-all">{{ json_encode($version->value, JSON_UNESCAPED_UNICODE) }}
                                    <a href="{{ $version->source_url }}" target="_blank" rel="noopener" class="block font-sans text-accent-600 hover:text-accent-700">{{ __('Källa') }}</a>
                                </td>
                                <td class="py-2 pr-4 tabular-nums whitespace-nowrap">{{ $version->valid_from->toDateString() }} – {{ $version->valid_to?->toDateString() }}</td>
                                <td class="py-2">
                                    @if($version->isVerified())
                                        <x-ui.badge tone="success">{{ $version->verified_by }}</x-ui.badge>
                                    @else
                                        <span class="inline-flex rounded-full bg-accent-50 px-3 py-1 text-xs font-medium text-accent-700">{{ __('Ej kontrollerad') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $row['key'] }}</td>
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

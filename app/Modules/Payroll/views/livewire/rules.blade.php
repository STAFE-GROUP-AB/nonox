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
                                            <button type="button" class="mt-1 block text-xs font-medium text-accent-600 hover:text-accent-700" wire:click="check({{ $version->id }})">{{ __('Kontrollera') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                            @if($checking === $version->id)
                                <tr>
                                    <td colspan="4" class="bg-gray-50 px-4 py-3">
                                        @if($version->source_quote)
                                            <p class="text-sm text-gray-700">{{ __('Källan säger:') }} <q>{{ $version->source_quote }}</q></p>
                                        @endif
                                        <p class="mt-1 text-sm"><a href="{{ $version->source_url }}" target="_blank" rel="noopener" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna källan och jämför värdet') }}</a></p>
                                        <label class="mt-3 flex items-start gap-2 text-sm">
                                            <input type="checkbox" class="mt-0.5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="confirmed">
                                            <span>{{ __('Jag har jämfört värdet med källan och tar ansvar för att det stämmer för vårt företag.') }}</span>
                                        </label>
                                        <x-input-error for="confirmed" class="mt-1" />
                                        <div class="mt-3 flex items-center gap-3">
                                            <x-button type="button" wire:click="verify">{{ __('Markera som kontrollerad') }}</x-button>
                                            <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('checking', null)">{{ __('Avbryt') }}</button>
                                        </div>
                                    </td>
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

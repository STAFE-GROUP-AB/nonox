<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6" x-data="overviewTabs(@js($tab))">
        <livewire:overview.overview-page />

        @if($canSeeStatistics)
            <div id="panel-statistik" role="tabpanel" aria-labelledby="tab-statistik" x-show="tab === 'statistik'" x-cloak>
                <livewire:overview.statistics-panel />
            </div>
        @endif

        <div x-show="tab === 'oversikt'" class="space-y-6">
            @if($firm)
                @include('partials.firm-activity', ['firm' => $firm])
            @endif

            @unless($hideGettingStarted)
                <x-ui.card :title="__('Kom igång')" :description="__('Ta det i den här ordningen. Du behöver inte göra allt i dag.')">
                    <x-slot:action>
                        <form method="POST" action="{{ route('dashboard.gettingStarted') }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="visible" value="0">
                            <button type="submit" class="text-sm font-medium text-gray-600 hover:text-gray-900 underline">{{ __('Dölj') }}</button>
                        </form>
                    </x-slot:action>

                    <ol class="divide-y divide-gray-100">
                        @foreach($steps as $step)
                            <li class="flex gap-4 py-4">
                                <span @class([
                                    'mt-0.5 flex size-7 flex-none items-center justify-center rounded-full text-sm font-semibold',
                                    'bg-accent-600 text-white' => $step['done'],
                                    'border-2 border-gray-300 text-gray-500' => ! $step['done'],
                                ]) aria-hidden="true">
                                    @if($step['done'])
                                        ✓
                                    @else
                                        {{ $loop->iteration }}
                                    @endif
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p @class(['font-medium', 'text-gray-500 line-through decoration-gray-300' => $step['done'], 'text-gray-900' => ! $step['done']])>
                                        {{ $step['title'] }}
                                        @if($step['done'])
                                            <span class="sr-only">{{ __('(klart)') }}</span>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 text-sm text-gray-600">{{ $step['text'] }}</p>
                                </div>

                                <div class="flex-none self-center">
                                    @if($step['soon'])
                                        <x-ui.badge>{{ __('Kommer snart') }}</x-ui.badge>
                                    @elseif(! $step['done'] && $step['link'] && ($step['allowed'] ?? $canManage))
                                        <x-ui.link :href="$step['link']">{{ __('Gör det nu') }}</x-ui.link>
                                    @elseif($step['done'] && $step['link'] && ($step['allowed'] ?? false))
                                        <x-ui.link :href="$step['link']">{{ __('Läs in fler') }}</x-ui.link>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>
            @endunless

            @if($hideGettingStarted)
                <form method="POST" action="{{ route('dashboard.gettingStarted') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="visible" value="1">
                    <button type="submit" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Visa Kom igång igen') }}</button>
                </form>
            @endif

            <p class="text-sm text-gray-500">
                {{ __('NONOX byggs i öppen källkod, en del i taget. Det som är markerat "Kommer snart" finns inte ännu.') }}
            </p>
        </div>
    </div>
</x-app-layout>

<div class="space-y-6">
    <x-ui.card :title="__('Kom igång med lön')" :description="__('Lönemodulen byggs steg för steg. Steg som inte är klara i systemet ännu är markerade.')">
        <ol class="divide-y divide-gray-100">
            @foreach($steps as $step)
                <li class="flex gap-4 py-4">
                    <span @class([
                        'mt-0.5 flex size-7 flex-none items-center justify-center rounded-full text-sm font-semibold',
                        'bg-accent-600 text-white' => $step['ready'],
                        'border-2 border-gray-300 text-gray-500' => ! $step['ready'],
                    ]) aria-hidden="true">{{ $step['ready'] ? '✓' : $loop->iteration }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-900">{{ $step['title'] }}</p>
                        <p class="mt-0.5 text-sm text-gray-600">{{ $step['text'] }}</p>
                    </div>
                    <div class="flex-none self-center">
                        @if($step['link'])
                            <x-ui.link :href="$step['link']">{{ __('Öppna') }}</x-ui.link>
                        @else
                            <x-ui.badge>{{ __('Kommer snart') }}</x-ui.badge>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </x-ui.card>
</div>

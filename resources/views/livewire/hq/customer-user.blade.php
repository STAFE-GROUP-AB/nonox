<div class="space-y-6">
    <x-ui.page-heading :title="$user->name" :lead="__('Bara uppgifter om kontot. Sidan är bara för läsning: du kan inte logga in som användaren, ändra eller radera något här.')">
        @if($user->anonymised_at)
            <x-slot:action><x-ui.badge>{{ __('Anonymiserad') }}</x-ui.badge></x-slot:action>
        @endif
    </x-ui.page-heading>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('Kontot')" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 text-sm">
                <div><dt class="font-medium text-gray-500">{{ __('E-post') }}</dt><dd class="mt-1 text-ink break-all">{{ $user->email }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Källa') }}</dt><dd class="mt-1 text-ink">{{ $source }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Skapad') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ $user->created_at?->format('Y-m-d H:i') }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Senast inloggad') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ $user->last_login_at?->format('Y-m-d H:i') ?? '–' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('Företag')">
            @if($companies === [])
                <p class="text-sm text-gray-600">{{ __('Användaren har inget företag än.') }}</p>
            @else
                <ul class="space-y-2 text-sm">
                    @foreach($companies as $membership)
                        <li>
                            <a href="{{ route('hq.customers.company', $membership['company']->id) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $membership['company']->name }}</a>
                            <span class="block text-xs text-gray-500">{{ $membership['roleLabel'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</div>

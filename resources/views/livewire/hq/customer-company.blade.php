<div class="space-y-6">
    <x-ui.page-heading :title="$company->name" :lead="__('Bara uppgifter om kontot. Inga belopp, verifikationer, fakturor eller uppgifter om anställda. Sidan är bara för läsning.')">
        <x-slot:action>
            <div class="flex flex-wrap gap-2">
                @if($company->isArchived())
                    <x-ui.badge>{{ __('Arkiverat') }}</x-ui.badge>
                @else
                    <x-ui.badge tone="success">{{ __('Aktivt') }}</x-ui.badge>
                @endif
                @if($company->isTestCompany())
                    <x-ui.badge>{{ __('Testföretag') }}</x-ui.badge>
                @endif
            </div>
        </x-slot:action>
    </x-ui.page-heading>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('Företaget')" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 text-sm">
                <div><dt class="font-medium text-gray-500">{{ __('Organisationsnummer') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ App\Support\Hq\CustomerDirectory::displayedOrgNumber($company) }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Företagsform') }}</dt><dd class="mt-1 text-ink">{{ $company->legal_form->label() }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Skapat') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ $company->created_at?->format('Y-m-d H:i') }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Arkiverat') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ $company->archived_at?->format('Y-m-d') ?? __('Nej') }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Senaste aktivitet') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ $company->last_activity_at?->format('Y-m-d H:i') ?? '–' }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Bokförda verifikationer') }}</dt><dd class="mt-1 text-ink tabular-nums" data-fact="posted-vouchers">{{ $company->posted_vouchers_count }}</dd></div>
                <div><dt class="font-medium text-gray-500">{{ __('Medlemmar') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ count($members) }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('Moduler')">
            <ul class="space-y-2 text-sm">
                @foreach($modules as $module)
                    <li class="flex items-baseline justify-between gap-3">
                        <span class="text-ink">{{ $module['name'] }}</span>
                        @if($module['enabled'])
                            <x-ui.badge tone="success">{{ __('På') }}@if($module['enabledAt']) {{ $module['enabledAt']->format('Y-m-d') }}@endif</x-ui.badge>
                        @else
                            <x-ui.badge>{{ __('Av') }}</x-ui.badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>

    <div class="overflow-x-auto rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
        <table class="min-w-full divide-y divide-stone-200 text-sm">
            <caption class="px-4 py-3 text-left text-lg font-bold text-ink">{{ __('Medlemmar') }}</caption>
            <thead class="bg-stone-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('Namn') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('Roll') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('Senast inloggad') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach($members as $member)
                    <tr wire:key="member-{{ $member['user']->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('hq.customers.user', $member['user']->id) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $member['user']->name }}</a>
                            <span class="block text-xs text-gray-500 break-all">{{ $member['user']->email }}</span>
                        </td>
                        <td class="px-4 py-3">{{ $member['roleLabel'] }}</td>
                        <td class="px-4 py-3 tabular-nums text-gray-600">{{ $member['lastLogin']?->format('Y-m-d H:i') ?? '–' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

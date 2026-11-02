<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Kom igång') }}
        </h1>
    </x-slot>

    <div class="max-w-3xl py-10 px-4 sm:px-6 lg:px-10 space-y-10">
        @if($invitations->isNotEmpty())
            <section class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900">{{ __('Du är inbjuden') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Någon har bjudit in dig till sitt företag. Gå med för att börja arbeta där.') }}</p>

                <ul class="mt-6 divide-y divide-gray-100">
                    @foreach($invitations as $invitation)
                        <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div>
                                <p class="font-medium text-gray-900">{{ $invitation->team->name }}</p>
                                @if($invitation->role && $role = Laravel\Jetstream\Jetstream::findRole($invitation->role))
                                    <p class="text-sm text-gray-600">{{ __('Som :role', ['role' => mb_strtolower($role->name)]) }}</p>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('team-invitations.accept-from-onboarding', $invitation->id) }}">
                                @csrf
                                <x-button>{{ __('Gå med') }}</x-button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div>
            <h3 class="text-2xl font-semibold text-gray-900">
                @if($invitations->isNotEmpty())
                    {{ __('…eller skapa ett eget företag') }}
                @else
                    {{ __('Skapa ditt företag') }}
                @endif
            </h3>
            <p class="mt-2 text-gray-600">{{ __('Allt i systemet hör till ett företag. Du kan lägga till fler företag senare och byta mellan dem uppe i menyn.') }}</p>
        </div>

        <livewire:company.create-company-form />
    </div>
</x-app-layout>

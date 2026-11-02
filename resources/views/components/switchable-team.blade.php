@props(['team', 'component' => 'dropdown-link'])

<form method="POST" action="{{ route('current-team.update') }}" x-data>
    @method('PUT')
    @csrf

    <!-- Hidden Team ID -->
    <input type="hidden" name="team_id" value="{{ $team->id }}">

    <x-dynamic-component :component="$component" href="#" x-on:click.prevent="$root.submit();">
        <div class="flex items-center">
            @if (Auth::user()->isCurrentTeam($team))
                <svg class="me-2 size-5 text-green-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            @endif

            <div @class(['truncate', 'text-gray-500' => $team->isArchived()])>{{ $team->name }}@if(Auth::user()->isCurrentTeam($team))<span class="sr-only"> {{ __('(valt företag)') }}</span>@endif</div>
            @if($team->isArchived())
                <span class="ms-2 shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ __('Arkiverat') }}</span>
            @endif
        </div>
    </x-dynamic-component>
</form>

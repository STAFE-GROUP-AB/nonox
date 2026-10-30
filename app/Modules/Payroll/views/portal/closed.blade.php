<x-guest-layout>
    <div class="mx-auto max-w-lg px-4 py-16">
        <div class="rounded-xl border border-gray-200 bg-white p-8">
            <h1 class="text-xl font-semibold text-gray-900">{{ __('Min sida är stängd') }}</h1>
            <p class="mt-3 text-sm text-gray-600">{{ __(':company använder inte längre lönemodulen, så Min sida med lönebesked och ansökningar är stängd. Fråga din arbetsgivare om du behöver ett gammalt lönebesked.', ['company' => $company->name]) }}</p>

            @php($others = $user->allTeams()->reject(fn ($team) => $team->id === $company->id))
            @if($others->isNotEmpty())
                <p class="mt-6 text-sm font-medium text-gray-900">{{ __('Byt till ett annat företag') }}</p>
                <ul class="mt-2 space-y-2">
                    @foreach($others as $other)
                        <li>
                            <form method="POST" action="{{ route('current-team.update') }}">
                                @method('PUT')
                                @csrf
                                <input type="hidden" name="team_id" value="{{ $other->id }}">
                                <button type="submit" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ $other->name }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('logout') }}" class="mt-8">
                @csrf
                <button type="submit" class="text-sm text-gray-600 underline">{{ __('Logga ut') }}</button>
            </form>
        </div>
    </div>
</x-guest-layout>

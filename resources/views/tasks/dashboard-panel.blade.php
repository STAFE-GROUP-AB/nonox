{{--
    "Att göra åt dig" on the company's Översikt: what every TodoProvider
    (App\Support\Overview\TodoProvider::TAG) has for the user here, such as
    tasks the accounting firm has handed to the client. Nothing when empty.
--}}
@php
    $todoItems = [];
    foreach (app()->tagged(App\Support\Overview\TodoProvider::TAG) as $todoProvider) {
        array_push($todoItems, ...$todoProvider->todos($user, $company, \Carbon\CarbonImmutable::today()));
    }
    $todoToday = \Carbon\CarbonImmutable::today()->toDateString();
@endphp
@if($todoItems !== [])
    <x-ui.card :title="__('Att göra åt dig')" :description="__('Uppgifter som har lämnats till dig i :company.', ['company' => $company->name])">
        <x-slot name="action">
            <a href="{{ route('tasks.index') }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Alla uppgifter') }}</a>
        </x-slot>
        <ul class="divide-y divide-stone-200">
            @foreach($todoItems as $todo)
                <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <a href="{{ $todo['url'] }}" class="min-w-0 font-medium text-ink hover:text-accent-700">{{ $todo['title'] }}</a>
                    <span class="flex items-center gap-3 text-sm text-gray-600">
                        @if($todo['subtitle'])<span>{{ $todo['subtitle'] }}</span>@endif
                        @if($todo['due'])
                            <span @class(['tabular-nums', 'font-semibold text-accent-700' => $todo['due'] < $todoToday])>{{ $todo['due'] < $todoToday ? __('Försenad, sista dag var :date', ['date' => $todo['due']]) : __('Sista dag :date', ['date' => $todo['due']]) }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    </x-ui.card>
@endif

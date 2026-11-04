{{--
    Företagsguiden on a page of the app (ADR-051): up to three articles that
    name this page's route and are written for the company's legal form and
    industry. Read from the cached guide, so it adds no query. Left out when
    the company has turned the guide off or the user has hidden the tips.
--}}
@props(['company'])

@php
    $tipsUser = auth()->user();
    $tips = $company && $tipsUser && $company->guide_enabled && ! $tipsUser->hidesPanel(App\Http\Controllers\GuideTipsController::PANEL, $company)
        ? App\Support\Guide\Guide::articlesForRoute(request()->route()?->getName(), $company)
        : [];
@endphp

@if($tips !== [])
    <aside class="px-4 pt-8 sm:px-6 lg:px-10" aria-labelledby="guide-tips-title" data-guide-tips>
        <div class="rounded-2xl bg-white p-5 ring-1 ring-stone-200">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="guide-tips-title" class="text-sm font-semibold text-ink">{{ __('Tips från Företagsguiden') }}</h2>
                <form method="POST" action="{{ route('guide.tips') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="visible" value="0">
                    <button type="submit" class="text-xs text-gray-500 underline hover:text-gray-700">{{ __('Dölj tips') }}</button>
                </form>
            </div>
            <ul class="mt-3 grid gap-3 sm:grid-cols-3">
                @foreach($tips as $tip)
                    <li>
                        <a href="{{ $tip->url() }}" class="block h-full rounded-xl bg-paper p-4 hover:bg-stone-100 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">
                            <span class="block text-sm font-semibold text-ink">{{ $tip->title }}</span>
                            <span class="mt-1 block text-sm text-gray-600">{{ $tip->description }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </aside>
@endif

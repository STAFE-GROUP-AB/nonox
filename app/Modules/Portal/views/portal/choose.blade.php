@extends('portal::portal.layout', ['settings' => null, 'company' => null, 'title' => __('Välj kundportal')])

@section('content')
    <h1 class="text-2xl font-bold tracking-tight">{{ __('Välj kundportal') }}</h1>
    @if($portals->isEmpty())
        <p class="mt-4 rounded-2xl bg-white p-6 text-gray-700 ring-1 ring-stone-200">{{ __('Du har ingen kundportal att öppna just nu. Kontakta företaget som bjöd in dig.') }}</p>
    @else
        <p class="mt-2 text-gray-700">{{ __('Du har tillgång till flera kundportaler. Välj vilken du vill öppna.') }}</p>
        <ul class="mt-6 grid gap-3 sm:grid-cols-2">
            @foreach($portals as $portal)
                @php($brand = App\Support\Branding\Brand::for($portal['company']))
                <li>
                    <form method="POST" action="{{ route('portal.enter') }}">
                        @csrf
                        <input type="hidden" name="contact" value="{{ $portal['contact']->id }}">
                        <button type="submit" class="flex w-full items-center gap-3 rounded-2xl bg-white p-4 text-left ring-1 ring-stone-200 hover:ring-stone-400">
                            @if($logo = $brand->logoDataUri())
                                <img src="{{ $logo }}" alt="" class="max-h-10 max-w-24">
                            @endif
                            <span>
                                <span class="block font-semibold">{{ $portal['company']->legal_name ?: $portal['company']->name }}</span>
                                <span class="block text-sm text-gray-600">{{ __('Som :customer', ['customer' => $portal['contact']->customer?->name]) }}</span>
                            </span>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
@endsection

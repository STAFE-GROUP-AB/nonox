{{--
    The document's pages with fields (ADR-046 amendment, gap 2, S6). Each page is an image rendered on the server; the
    signer's own fields are boxes over it (a click moves to the field's control) and, under the page, a list of
    labelled controls in reading order that belong to the signing form (form="signera-formular"). Earlier signers'
    values are in the image itself and locked; later signers' fields are empty outlines named by number only.
--}}
@php
    use App\Domain\Signing\Fields\FieldType;

    /** @var \App\Domain\Signing\Fields\SignerFields $fields */
    $input = 'mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-gray-900 focus:ring-gray-900';
    $pageCount = $fields->pages === null ? (int) $fields->fields->max('page') : count($fields->pages);
    $first = $fields->firstRequired();
    $groupsShown = [];
@endphp
<div class="mt-6" data-signing-fields>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-stone-50 p-3 text-sm ring-1 ring-stone-200">
        <p>{{ $fields->own()->isEmpty() ? __('Du har inga fält att fylla i, bara att signera.') : __('Du har :count fält att fylla i. De är markerade i dokumentet och listas under varje sida.', ['count' => $fields->own()->count()]) }}</p>
        @if($first)
            <a href="#falt-{{ $first->id }}" data-next-required class="inline-flex min-h-11 items-center rounded-lg px-3 font-semibold ring-1 ring-gray-300 hover:bg-white">{{ __('Nästa obligatoriska fält') }}</a>
        @endif
    </div>

    @if($fields->pages === null)
        <p role="status" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">{{ __('Sidorna kan inte visas som bilder just nu. Öppna PDF:en för att läsa dokumentet; fälten listas nedan per sida.') }}</p>
    @endif

    @for($page = 1; $page <= $pageCount; $page++)
        @php
            $meta = $fields->pages === null ? null : collect($fields->pages)->firstWhere('page', $page);
            $onPage = $fields->onPage($page);
            $ownOnPage = $onPage->filter(fn ($field) => $fields->isOwn($field));
        @endphp
        <section aria-labelledby="sida-{{ $page }}" class="mt-6">
            <h3 id="sida-{{ $page }}" class="text-sm font-semibold text-gray-700">{{ __('Sida :page av :count', ['page' => $page, 'count' => $pageCount]) }}</h3>

            @if($meta)
                <div class="relative mt-2 w-full overflow-hidden rounded-lg bg-white ring-1 ring-stone-300" style="aspect-ratio: {{ $meta['width'] }} / {{ $meta['height'] }}">
                    <img src="{{ route('signing.page', ['token' => $token, 'page' => $page]) }}" alt="{{ __('Sida :page av dokumentet', ['page' => $page]) }}" loading="{{ $page <= 2 ? 'eager' : 'lazy' }}" class="absolute inset-0 h-full w-full select-none" draggable="false">
                    <div aria-hidden="true">
                        @foreach($onPage as $field)
                            @php($box = $field->box())
                            @php($place = "left: {$box['left']}%; top: {$box['top']}%; width: {$box['width']}%; height: {$box['height']}%;")
                            @if($fields->isOwn($field))
                                @if($field->type->isAutomatic() || $field->type->isMark())
                                    <span class="absolute flex items-center overflow-hidden rounded-sm border-2 bg-amber-100/70 px-1 text-[0.65rem] leading-tight text-gray-900" style="{{ $place }} border-color: {{ $color }}">{{ $field->type === FieldType::Date ? now()->setTimezone('Europe/Stockholm')->format('Y-m-d') : $field->label }}</span>
                                @else
                                    <label for="falt-{{ $field->id }}" class="absolute flex cursor-pointer items-center overflow-hidden rounded-sm border-2 bg-amber-100/70 px-1 text-[0.65rem] leading-tight text-gray-900 hover:bg-amber-200/80" style="{{ $place }} border-color: {{ $color }}">@php($shown = $field->type === FieldType::Radio ? $field->radioValue() : $field->label)<span data-field-preview="{{ $field->id }}" data-placeholder="{{ $shown }}">{{ $shown }}</span></label>
                                @endif
                            @elseif($fields->isLocked($field))
                                <span class="absolute rounded-sm border border-gray-400" style="{{ $place }}" title="{{ __('Ifyllt av :signer', ['signer' => $fields->signerName($field)]) }}"></span>
                            @else
                                <span class="absolute flex items-center overflow-hidden rounded-sm border border-dashed border-gray-500 bg-white/60 px-1 text-[0.6rem] leading-tight text-gray-600" style="{{ $place }}">{{ $fields->signerName($field) }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if($onPage->isNotEmpty())
                <div class="mt-3 space-y-4">
                    @foreach($onPage as $field)
                        @if($fields->isOwn($field))
                            @php($required = $field->required ? ' '.__('(obligatoriskt)') : '')
                            @switch($field->type)
                                @case(FieldType::Text)
                                    <div>
                                        <label for="falt-{{ $field->id }}" class="text-sm font-medium">{{ $field->label }}{{ $required }}</label>
                                        <input id="falt-{{ $field->id }}" name="fields[{{ $field->id }}]" form="signera-formular" type="text" maxlength="{{ min($field->rule('max_length') ?? 500, 500) }}" value="{{ old('fields.'.$field->id) }}" class="{{ $input }}" data-field-input="{{ $field->id }}" @required($field->required) @error('fields.'.$field->id) aria-invalid="true" aria-describedby="fel-{{ $field->id }}" @enderror>
                                    </div>
                                    @break
                                @case(FieldType::Number)
                                    @php($min = $field->rules['min'] ?? null)
                                    @php($max = $field->rules['max'] ?? null)
                                    <div>
                                        <label for="falt-{{ $field->id }}" class="text-sm font-medium">{{ $field->label }}{{ $required }}</label>
                                        <input id="falt-{{ $field->id }}" name="fields[{{ $field->id }}]" form="signera-formular" type="text" inputmode="decimal" autocomplete="off" value="{{ old('fields.'.$field->id) }}" class="{{ $input }} tabular-nums" data-field-input="{{ $field->id }}" @required($field->required) @error('fields.'.$field->id) aria-invalid="true" aria-describedby="hjalp-{{ $field->id }} fel-{{ $field->id }}" @else aria-describedby="hjalp-{{ $field->id }}" @enderror>
                                        <p id="hjalp-{{ $field->id }}" class="mt-1 text-xs text-gray-600">{{ ($field->rule('decimals') ?? 0) === 0 ? __('Ett heltal.') : __('Ett tal med högst :decimals decimaler.', ['decimals' => $field->rule('decimals')]) }}@if($min !== null) {{ __('Minst :min.', ['min' => str_replace('.', ',', (string) $min)]) }}@endif @if($max !== null) {{ __('Högst :max.', ['max' => str_replace('.', ',', (string) $max)]) }}@endif</p>
                                    </div>
                                    @break
                                @case(FieldType::Checkbox)
                                    <label class="flex min-h-11 items-start gap-3 text-sm">
                                        <input id="falt-{{ $field->id }}" name="fields[{{ $field->id }}]" form="signera-formular" type="checkbox" value="1" class="mt-0.5 size-5 rounded border-gray-400" data-field-input="{{ $field->id }}" @checked(old('fields.'.$field->id) === '1') @required($field->required) @error('fields.'.$field->id) aria-invalid="true" aria-describedby="fel-{{ $field->id }}" @enderror>
                                        <span>{{ $field->label }}{{ $required }}</span>
                                    </label>
                                    @break
                                @case(FieldType::Dropdown)
                                    <div>
                                        <label for="falt-{{ $field->id }}" class="text-sm font-medium">{{ $field->label }}{{ $required }}</label>
                                        <select id="falt-{{ $field->id }}" name="fields[{{ $field->id }}]" form="signera-formular" class="{{ $input }}" data-field-input="{{ $field->id }}" @required($field->required) @error('fields.'.$field->id) aria-invalid="true" aria-describedby="fel-{{ $field->id }}" @enderror>
                                            <option value="">{{ __('Välj') }}</option>
                                            @foreach($field->options ?? [] as $option)
                                                <option value="{{ $option }}" @selected(old('fields.'.$field->id) === $option)>{{ $option }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @break
                                @case(FieldType::Radio)
                                    @if(! in_array($field->group_key, $groupsShown, true))
                                        @php($groupsShown[] = $field->group_key)
                                        @php($group = $fields->radioGroups()[$field->group_key] ?? collect())
                                        <fieldset>
                                            <legend class="text-sm font-medium">{{ $field->label }}{{ $group->contains('required', true) ? ' '.__('(obligatoriskt)') : '' }}</legend>
                                            <div class="mt-1 flex flex-wrap gap-x-5">
                                                @foreach($group as $button)
                                                    <label class="inline-flex min-h-11 items-center gap-2 text-sm">
                                                        <input id="falt-{{ $button->id }}" type="radio" name="radio[{{ $field->group_key }}]" form="signera-formular" value="{{ $button->id }}" data-field-input="{{ $button->id }}" data-radio-group="{{ $field->group_key }}" @checked((string) old('radio.'.$field->group_key) === (string) $button->id) @required($group->contains('required', true)) @error('fields.'.$field->id) aria-invalid="true" aria-describedby="fel-{{ $field->id }}" @enderror>
                                                        {{ $button->radioValue() }}@if($button->page !== $page) <span class="text-gray-600">{{ __('(sida :page)', ['page' => $button->page]) }}</span>@endif
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endif
                                    @break
                                @default
                                    <p class="text-sm"><span class="font-medium">{{ $field->label }}:</span> <span class="text-gray-700">{{ match ($field->type) {
                                        FieldType::Date => __('fylls i med dagens datum när du signerar.'),
                                        FieldType::Name => __('fylls i med namnet du skriver nedan.'),
                                        FieldType::Initials => __('fylls i med dina initialer nedan.'),
                                        default => __('fylls i med din underskrift nedan.'),
                                    } }}</span></p>
                            @endswitch
                            @error('fields.'.$field->id)
                                <p id="fel-{{ $field->id }}" class="text-sm font-medium text-red-700">{{ $message }}</p>
                            @enderror
                        @elseif($fields->isLocked($field))
                            <p class="text-sm text-gray-700"><span class="font-medium">{{ $field->label }}</span> · {{ __('ifyllt av :signer', ['signer' => $fields->signerName($field)]) }}: {{ $fields->shownValue($field) ?? __('lämnades tomt') }}</p>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>
    @endfor
</div>

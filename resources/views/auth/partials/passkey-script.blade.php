{{-- Passkeys in the browser (ADR-029), bundled by Vite. The start pages work without a build, so the script is added only when one exists. --}}
@if(Illuminate\Support\Facades\Vite::isRunningHot() || is_file(public_path('build/manifest.json')))
    @vite('resources/js/passkeys.js')
@endif

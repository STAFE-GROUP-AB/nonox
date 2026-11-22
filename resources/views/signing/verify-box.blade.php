{{-- The QR code and short link to the public Verify page (ADR-046 S12). Nothing while the page is switched off. --}}
@php($verifyUrl = App\Domain\Signing\Verify\VerifyLink::url($hash))
@if($verifyUrl)
    <table class="meta" style="margin-top: 5mm">
        <tr>
            <td style="width: 32mm; border-bottom: 0"><img src="{{ App\Domain\Signing\Verify\VerifyLink::qrDataUri($hash) }}" width="110" height="110" alt="{{ $b('QR-kod till kontrollsidan') }}"></td>
            <td style="border-bottom: 0">
                <strong>{{ $b('Kontrollera att filen är oförändrad') }}</strong><br>
                <span class="muted">{{ $b('Skanna koden eller öppna länken och välj filen. Filen lämnar aldrig din enhet; bara dess kontrollsumma skickas.') }}</span><br>
                <span class="mono">{{ route('signing.verifyPage') }}<br>#sha256={{ substr($hash, 0, 32) }}<br>{{ substr($hash, 32) }}</span>
            </td>
        </tr>
    </table>
@endif

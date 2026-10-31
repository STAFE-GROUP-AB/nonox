<?php

declare(strict_types=1);

namespace App\Support\Cookies;

/**
 * Every cookie and browser storage NONOX uses, per category, for the cookie
 * banner and the cookie page (Lag om elektronisk kommunikation 9 kap. 28 §,
 * GDPR). Only the necessary category is in use today; anything optional
 * added later must be listed here and load only after consent
 * (window.nonoxConsent.allows('statistics')).
 */
final class CookieInventory
{
    /** Raised when the categories or their contents change, so everyone is asked again. */
    public const int VERSION = 1;

    /**
     * @return list<array{key: string, name: string, description: string, required: bool, items: list<array{name: string, purpose: string, duration: string, type: string}>}>
     */
    public static function categories(): array
    {
        $session = (string) config('session.cookie');

        return [
            [
                'key' => 'necessary',
                'name' => 'Nödvändiga',
                'description' => 'Krävs för att sidan, inloggningen och säkerheten ska fungera. De kan inte väljas bort.',
                'required' => true,
                'items' => [
                    ['name' => $session, 'purpose' => 'Håller dig inloggad och kopplar ihop dina sidvisningar under besöket.', 'duration' => (int) config('session.lifetime').' minuter efter senaste aktivitet', 'type' => 'Cookie (förstapart)'],
                    ['name' => 'XSRF-TOKEN', 'purpose' => 'Skyddar formulär mot förfalskade anrop (CSRF).', 'duration' => 'Samma som sessionen', 'type' => 'Cookie (förstapart)'],
                    ['name' => 'nonox-consent', 'purpose' => 'Sparar ditt val i den här rutan.', 'duration' => '12 månader', 'type' => 'Lokal lagring i webbläsaren'],
                    ['name' => 'nonox-flyttguide', 'purpose' => 'Kommer ihåg vilka steg i flyttguiden du har bockat av.', 'duration' => 'Tills du rensar webbläsaren', 'type' => 'Lokal lagring i webbläsaren'],
                ],
            ],
            [
                'key' => 'statistics',
                'name' => 'Statistik',
                'description' => 'Anonym statistik över hur sidorna används. Används inte i dag; om vi börjar göra det laddas den bara om du har sagt ja.',
                'required' => false,
                'items' => [],
            ],
            [
                'key' => 'marketing',
                'name' => 'Marknadsföring',
                'description' => 'Annonsering och uppföljning av kampanjer. Används inte, och vi har inga planer på det.',
                'required' => false,
                'items' => [],
            ],
        ];
    }
}

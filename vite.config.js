import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // The staff ledger kiosk is its own entry: an offline page with its own CSS (ADR-022).
            // Passkeys are their own entry too, for the sign-in page, which does not load app.js (ADR-029).
            // So is the Lager scanning app (/lager/skanna), which works offline (ADR-040).
            // So are the developer site (/utvecklare) and the manual (/manual), which load only their own small scripts.
            // So is the public signing page (/signera, ADR-046), with its strict content security policy.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/passkeys.js', 'resources/js/developer-portal.js', 'resources/js/manual.js', 'resources/js/signing.js', 'app/Modules/StaffLedger/resources/js/kiosk.js', 'app/Modules/Inventory/resources/js/scanner.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});

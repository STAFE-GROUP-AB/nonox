import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // The staff ledger kiosk is its own entry: an offline page with its own CSS (ADR-022).
            // Passkeys are their own entry too, for the sign-in page, which does not load app.js (ADR-029).
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/passkeys.js', 'app/Modules/StaffLedger/resources/js/kiosk.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});

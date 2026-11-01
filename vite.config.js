import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // The staff ledger kiosk is its own entry: an offline page with its own CSS (ADR-022).
            input: ['resources/css/app.css', 'resources/js/app.js', 'app/Modules/StaffLedger/resources/js/kiosk.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});

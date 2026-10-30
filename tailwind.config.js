import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/*
 * NONOX design tokens (AGENTS.md §11.1), the 2027 look of the landing page:
 * one accent colour, a warm vermilion, on warm neutrals with near-black ink.
 * The accent is CSS variables (resources/css/app.css): a company that has
 * chosen its own colour under Varumärke gets its own palette instead
 * (App\Support\Branding\Brand::interfacePalette, set in the app layout).
 * accent-500 is the bright tone for marks and large surfaces; buttons use
 * accent-600 (4.9:1 against white) and text links accent-600/700, so text
 * meets WCAG 2.2 AA. Jetstream's components use `indigo` and `gray`, so those
 * names are mapped to the NONOX palette instead of rewriting every component.
 */
const accent = Object.fromEntries(
    [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((shade) => [shade, `rgb(var(--accent-${shade}) / <alpha-value>)`]),
);

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            colors: {
                accent,
                indigo: accent,
                gray: colors.stone,
                paper: '#f7f5f0',
                ink: '#17171a',
            },
            fontFamily: {
                sans: ['ui-sans-serif', 'system-ui', '-apple-system', '"Segoe UI"', 'Roboto', '"Helvetica Neue"', 'Arial', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms, typography],
};

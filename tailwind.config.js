import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/*
 * NONOX design tokens (AGENTS.md §11.1): a single accent colour, falu red,
 * and warm neutrals. Jetstream's components use `indigo` and `gray`, so those
 * names are mapped to the NONOX palette instead of rewriting every component.
 */
const accent = {
    50: '#fbf3f1',
    100: '#f6e6e2',
    200: '#ecc8c0',
    300: '#dd9e90',
    400: '#c9705e',
    500: '#b04a37',
    600: '#9e2a1b',
    700: '#86231a',
    800: '#6e1f18',
    900: '#5c1d17',
    950: '#320c09',
};

/*
 * The 2027 look from the NONOX landing page: near-black ink for selected
 * states and a bright signal colour for what is late or waiting.
 */
const signal = {
    50: '#fff3ee',
    100: '#ffe4da',
    200: '#ffc6b2',
    500: '#ff4a1c',
    600: '#eb3d10',
    700: '#c2300c',
    800: '#9a2810',
};

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
                signal,
            },
            fontFamily: {
                sans: ['ui-sans-serif', 'system-ui', '-apple-system', '"Segoe UI"', 'Roboto', '"Helvetica Neue"', 'Arial', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms, typography],
};

import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"DM Sans"', 'Figtree', ...defaultTheme.fontFamily.sans],
                display: ['"Fraunces"', 'Georgia', 'serif'],
            },
            colors: {
                brand: {
                    ink: '#0f1c24',
                    mist: '#e8eef2',
                    fog: '#c5d4de',
                    sea: '#1a6b7a',
                    deep: '#0d3d47',
                    sand: '#f2ebe3',
                    ember: '#c45c26',
                },
            },
        },
    },

    plugins: [forms],
};

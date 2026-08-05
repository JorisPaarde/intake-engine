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
                sans: ['-apple-system', 'BlinkMacSystemFont', '"SF Pro Text"', '"SF Pro Display"', ...defaultTheme.fontFamily.sans],
                display: ['-apple-system', 'BlinkMacSystemFont', '"SF Pro Display"', ...defaultTheme.fontFamily.sans],
                marketing: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    ink: '#1D1D1F',
                    mist: '#F5F5F7',
                    fog: '#D2D2D7',
                    sea: '#0071E3',
                    deep: '#1D1D1F',
                    sand: '#F5F5F7',
                    ember: '#B42318',
                },
                marketing: {
                    ink: '#18201d',
                    muted: '#5e6862',
                    paper: '#fbfaf6',
                    mist: '#eef1ec',
                    green: '#315f4f',
                    'green-dark': '#15392f',
                    leaf: '#6f8f78',
                    amber: '#e7ad52',
                    coral: '#a84832',
                    line: 'rgba(24, 32, 29, 0.14)',
                },
            },
        },
    },

    plugins: [forms],
};

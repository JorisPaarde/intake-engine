import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/*
 * Visuele huisstijl (BL-106): rustig salie/bosgroen met oker voor aandacht en
 * terracotta voor labels. De standaard Tailwind-kleurschalen worden hier
 * herdefinieerd, zodat bestaande views zonder markupwijziging meekleuren:
 *   gray            → warm, licht groengetint neutraal
 *   indigo/blue/sky → bosgroen (primair, links, focus)
 *   green/emerald   → saliegroen (klaar / lijkt goed)
 *   amber/yellow    → oker (controle nodig)
 *   red/rose        → terracotta (aanvulling nodig / fout)
 */
const ink = {
    50: '#f7f8f5',
    100: '#eef1ec',
    200: '#e2e6df',
    300: '#cfd5cc',
    400: '#9ba49d',
    500: '#6e7872',
    600: '#5a645e',
    700: '#414b45',
    800: '#2c3631',
    900: '#1e2723',
    950: '#18201d',
};

const forest = {
    50: '#eef4f0',
    100: '#dce8e0',
    200: '#bcd3c4',
    300: '#93b5a0',
    400: '#6f8f78',
    500: '#4a7a64',
    600: '#315f4f',
    700: '#285243',
    800: '#1f4538',
    900: '#15392f',
    950: '#0e2921',
};

const sage = {
    50: '#f0f5f1',
    100: '#e1ebe3',
    200: '#c6d9cb',
    300: '#a3c0ab',
    400: '#86a890',
    500: '#6f8f78',
    600: '#577a61',
    700: '#44634d',
    800: '#34503d',
    900: '#284031',
    950: '#15261b',
};

const ochre = {
    50: '#fbf4e8',
    100: '#f6e7cb',
    200: '#eed3a1',
    300: '#e8c079',
    400: '#e7ad52',
    500: '#d6983a',
    600: '#b67c2b',
    700: '#8d5e22',
    800: '#6d4920',
    900: '#553a1c',
    950: '#2e1e0c',
};

const terracotta = {
    50: '#faf1ed',
    100: '#f5e0d8',
    200: '#eac3b4',
    300: '#dc9e88',
    400: '#c9765d',
    500: '#b95c42',
    600: '#a84832',
    700: '#8c3b28',
    800: '#733223',
    900: '#5d2a1f',
    950: '#33150d',
};

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', ...defaultTheme.fontFamily.sans],
                display: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', ...defaultTheme.fontFamily.sans],
                marketing: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                gray: ink,
                slate: ink,
                zinc: ink,
                neutral: ink,
                indigo: forest,
                blue: forest,
                sky: forest,
                green: sage,
                emerald: sage,
                teal: sage,
                amber: ochre,
                yellow: ochre,
                orange: ochre,
                red: terracotta,
                rose: terracotta,
                brand: {
                    ink: '#18201d',
                    mist: '#eef1ec',
                    fog: '#cfd5cc',
                    sea: '#15392f',
                    deep: '#18201d',
                    sand: '#eef1ec',
                    ember: '#a84832',
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
            // Strakke, bijna rechte hoeken zoals in de productmockups.
            borderRadius: {
                none: '0',
                sm: '2px',
                DEFAULT: '3px',
                md: '3px',
                lg: '4px',
                xl: '4px',
                '2xl': '6px',
                '3xl': '8px',
            },
            boxShadow: {
                sm: '0 1px 2px rgba(24, 32, 29, 0.04)',
                DEFAULT: '0 1px 3px rgba(24, 32, 29, 0.06)',
                md: '0 6px 18px rgba(24, 32, 29, 0.06)',
                lg: '0 14px 40px rgba(24, 32, 29, 0.08)',
                xl: '0 22px 60px rgba(24, 32, 29, 0.10)',
            },
        },
    },

    plugins: [forms],
};

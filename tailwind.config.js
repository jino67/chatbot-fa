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
                sans: ['"Instrument Sans"', ...defaultTheme.fontFamily.sans],
                display: ['"Bricolage Grotesque"', '"Instrument Sans"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Cobalt : la couleur de la marque. 900 et 950 servent de « marine » (fonds sombres, texte).
                brand: {
                    50: '#EDF1FE', 100: '#DCE4FD', 200: '#B9C8FB', 300: '#8FA5F7', 400: '#5F7DF0',
                    500: '#3A5BE6', 600: '#2340D9', 700: '#1A32B0', 800: '#16298C', 900: '#101B5B', 950: '#0B1340',
                },
                // Safran : l'accent, reserve aux appels a l'action et aux points d'attention.
                accent: {
                    50: '#FFF9E5', 100: '#FFF0BF', 200: '#FFE38A', 300: '#FFD466', 400: '#FFC533',
                    500: '#FFB400', 600: '#E09A00', 700: '#B87800',
                },
                mist: '#F1F4FB',
            },
            boxShadow: {
                lift: '0 1px 2px rgba(11,19,64,.06), 0 8px 24px -8px rgba(11,19,64,.18)',
            },
        },
    },

    plugins: [forms],
};

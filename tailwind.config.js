import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Instrument Sans"', ...defaultTheme.fontFamily.sans],
                // Unbounded : large et ronde, elle prolonge le cercle du logo. Titres et grands chiffres.
                display: ['"Unbounded"', '"Instrument Sans"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Cobalt : la couleur de la marque. 900 et 950 servent de « marine » (fonds sombres, texte).
                brand: {
                    50: '#EDF1FE', 100: '#DCE4FD', 200: '#B9C8FB', 300: '#8FA5F7', 400: '#5F7DF0',
                    500: '#3A5BE6', 600: '#2340D9', 700: '#1A32B0', 800: '#16298C', 900: '#101B5B', 950: '#0B1340',
                },
                // Safran : la voix de l'assistant, l'accent des appels a l'action.
                accent: {
                    50: '#FFF9E5', 100: '#FFF0BF', 200: '#FFE38A', 300: '#FFD466', 400: '#FFC533',
                    500: '#FFB400', 600: '#E09A00', 700: '#B87800',
                },
                // Hibiscus et feuille : reserves aux formes et aux illustrations, jamais au texte courant.
                hibiscus: { 400: '#F2668B', 500: '#E8336D', 600: '#C9245A' },
                feuille: { 400: '#34C38F', 500: '#12A574', 600: '#0C8560' },
                mist: '#F1F4FB',
            },
            boxShadow: {
                lift: '0 1px 2px rgba(11,19,64,.06), 0 8px 24px -8px rgba(11,19,64,.18)',
                pop: '0 2px 4px rgba(11,19,64,.08), 0 18px 40px -12px rgba(11,19,64,.35)',
            },
            keyframes: {
                'toast-in': { from: { opacity: 0, transform: 'translateY(-8px) scale(.98)' }, to: { opacity: 1, transform: 'none' } },
                'bar-grow': { from: { transform: 'scaleX(0)' }, to: { transform: 'scaleX(1)' } },
            },
            animation: {
                'toast-in': 'toast-in .35s cubic-bezier(.2,.9,.3,1) both',
                'bar-grow': 'bar-grow .9s cubic-bezier(.2,.8,.2,1) .15s both',
            },
        },
    },

    plugins: [forms],
};

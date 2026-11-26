import forms from '@tailwindcss/forms';

/**
 * Identidad OBMAX (ver diseño de referencia):
 *  naranja  #F7791E  acción principal, siempre con texto carbón
 *  carbon   #1E1E1E  menú lateral, títulos, texto
 *  hormigon #F6F4F1  fondo de trabajo
 *  profundo #B4500B  enlaces y montos destacados
 *  grafito  #6B6560  texto secundario
 *  linea    #E4DFD9  bordes y separadores
 */
/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/views/**/*.blade.php', './resources/js/**/*.{vue,ts}'],
    theme: {
        extend: {
            colors: {
                naranja: { DEFAULT: '#F7791E', profundo: '#B4500B', oscuro: '#8A3D08' },
                carbon: { DEFAULT: '#1E1E1E', 2: '#2A2826', 3: '#4A4541' },
                hormigon: '#F6F4F1',
                grafito: '#6B6560',
                linea: { DEFAULT: '#E4DFD9', fuerte: '#C9C4BE' },
                estado: {
                    'neutro-bg': '#EFECE8', neutro: '#4A4541',
                    'alerta-bg': '#FFF3D6', alerta: '#8A5A00',
                    'info-bg': '#E8EFF7', info: '#2D5B8A',
                    'ok-bg': '#E6F4EC', ok: '#1E7A46',
                    'error-bg': '#FDECEA', error: '#B42318', 'error-borde': '#F1B5AE',
                },
            },
            fontFamily: {
                titulo: ['"Archivo Variable"', 'Archivo', 'sans-serif'],
                sans: ['Barlow', 'system-ui', 'sans-serif'],
            },
            borderRadius: { tarjeta: '10px', boton: '8px' },
        },
    },
    plugins: [forms],
};

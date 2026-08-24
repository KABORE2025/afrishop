import forms from '@tailwindcss/forms';

/*
 * =====================================================================
 *  TAILWIND — VERSION 3, ET C'EST UN CHOIX
 * =====================================================================
 *  Tailwind 4 exige Chrome 111, Safari 16.4 ou Firefox 128 — tous de
 *  2023 ou plus récents. Sur un navigateur plus ancien, il ne dégrade
 *  pas : la mise en page casse. Le parc burkinabè compte beaucoup
 *  d'Android anciens dont le navigateur n'est plus mis à jour, et un
 *  gérant de boutique qui voit une page cassée ne signale rien — il
 *  arrête de s'en servir.
 *
 *  Tailwind 3 n'a rien qui manque à ce projet. La version 4 sera
 *  envisageable le jour où les journaux de connexion diront que le parc
 *  a tourné.
 *
 *  LA PALETTE VIENT DES VARIABLES CSS DE `layout.blade.php`.
 *  Elle n'est pas réinventée : les deux gabarits — vitrine publique en
 *  CSS en ligne, écrans connectés en Tailwind — doivent produire le
 *  même produit à l'œil. Une couleur qui change ici doit changer là-bas
 *  aussi. C'est la seule duplication assumée du projet, et elle est
 *  signalée des deux côtés.
 * =====================================================================
 */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            colors: {
                /* --brun : l'identité. Bandeaux, titres, éléments actifs. */
                brun: {
                    DEFAULT: '#7a3e12',
                    clair:   '#c2703a',
                },
                fond:     '#faf7f3',
                surface:  '#ffffff',
                bord:     '#e6dcd0',
                texte:    '#1a1a1a',
                gris:     '#6b6257',

                /* Sémantiques. Le vert ne veut PAS dire « livré » : il
                 * veut dire « rien à faire ». Un colis livré dont les
                 * fonds sont en séquestre n'est pas vert. */
                succes: '#2f7d4f',
                alerte: '#b3261e',
                attente:'#a1690f',
            },

            fontFamily: {
                /* Polices système uniquement : zéro octet à télécharger,
                 * affichage immédiat. Même règle que la vitrine. */
                sans: ['system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
            },

            borderRadius: {
                carte: '12px',
            },
        },
    },

    plugins: [forms],
};

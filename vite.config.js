import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

/*
 * =====================================================================
 *  CHAÎNE DE CONSTRUCTION DU FRONT
 * =====================================================================
 *  Elle ne concerne QUE les écrans connectés — espace vendeur, console
 *  d'administration, panier et tunnel de commande.
 *
 *  LA VITRINE PUBLIQUE N'EN DÉPEND PAS, ET C'EST VOLONTAIRE.
 *  `resources/views/layout.blade.php` garde son CSS en ligne et son
 *  absence totale de JavaScript : le client qui scanne une étiquette
 *  est dans la rue, en 2G, sur un téléphone d'entrée de gamme. Lui
 *  faire télécharger un bundle avant d'afficher un verdict serait une
 *  régression, pas une modernisation.
 *
 *  Deux gabarits, deux publics, deux contraintes. Voir
 *  `resources/views/layouts/app.blade.php`.
 * =====================================================================
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],

    build: {
        /*
         * Cible volontairement conservatrice. Le parc burkinabè compte
         * beaucoup d'Android anciens : compiler pour le dernier
         * navigateur en date produirait un bundle qui ne s'exécute pas
         * chez une partie des vendeurs, sans le moindre message
         * d'erreur — juste une page blanche.
         */
        target: ['es2019', 'chrome80', 'safari13'],

        /* Un bundle plus petit vaut mieux qu'un débogage plus facile
         * quand la bande passante se paie au mégaoctet. */
        sourcemap: false,
    },
});

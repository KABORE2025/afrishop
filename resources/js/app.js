import Alpine from 'alpinejs';
import { api, jeton, ErreurApi, fcfa, dateFr } from './api.js';

/*
 * =====================================================================
 *  POINT D'ENTRÉE DES ÉCRANS CONNECTÉS
 * =====================================================================
 *  Alpine plutôt qu'un framework complet : les écrans vendeur sont des
 *  listes, des formulaires et quelques boutons. Une application à
 *  composants coûterait dix fois le poids pour la même chose, sur des
 *  connexions où le poids se paie.
 *
 *  Rappel : ce fichier n'est chargé QUE par
 *  `resources/views/layouts/app.blade.php`. La vitrine publique et la
 *  page de vérification QR n'exécutent aucun JavaScript.
 * =====================================================================
 */

window.api = api;
window.fcfa = fcfa;
window.dateFr = dateFr;

/*
 * Formulaire générique.
 *
 * Il existe pour une raison précise : l'API renvoie ses messages de
 * validation en français, déjà rédigés et souvent explicatifs
 * (« Cette sous-commande est « livree » : elle ne peut plus être
 * expédiée. »). Les réécrire côté écran produirait deux vocabulaires
 * pour la même règle. On les affiche tels quels.
 */
Alpine.data('formulaire', (config = {}) => ({
    donnees: config.donnees ?? {},
    erreurs: {},
    message: null,
    typeMessage: 'succes',
    enCours: false,

    async soumettre(methode, chemin) {
        if (this.enCours) return;   // double-clic = double commande

        this.enCours = true;
        this.erreurs = {};
        this.message = null;

        try {
            const resultat = await api[methode](chemin, this.donnees);
            this.typeMessage = 'succes';
            if (config.surSucces) config.surSucces(resultat, this);
            return resultat;
        } catch (e) {
            this.typeMessage = e instanceof ErreurApi && e.sansBoutique ? 'information' : 'erreur';
            this.message = e.message;
            if (e instanceof ErreurApi) this.erreurs = e.erreurs;
            if (config.surErreur) config.surErreur(e, this);
        } finally {
            this.enCours = false;
        }
    },

    /** Première erreur d'un champ, ou null. */
    erreur(champ) {
        return this.erreurs[champ]?.[0] ?? null;
    },
}));

/*
 * Liste paginée. Le squelette de tous les écrans « mes commandes »,
 * « mes produits », « mes reversements ».
 */
Alpine.data('liste', (chemin, filtresInitiaux = {}) => ({
    elements: [],
    pagination: null,
    filtres: filtresInitiaux,
    chargement: true,
    erreur: null,
    /** Vrai quand la candidature n'est pas encore validée : ce n'est
     *  pas une erreur, et l'écran doit le dire autrement. */
    sansBoutique: false,

    init() { this.charger(); },

    async charger(page = 1) {
        this.chargement = true;
        this.erreur = null;

        const params = new URLSearchParams({ page, ...this.nettoyer(this.filtres) });

        try {
            const r = await api.get(`${chemin}?${params}`);
            this.elements = r.data ?? r;
            this.pagination = r.meta ?? null;
        } catch (e) {
            this.sansBoutique = e instanceof ErreurApi && e.sansBoutique;
            this.erreur = e.message;
            this.elements = [];
        } finally {
            this.chargement = false;
        }
    },

    /* Un filtre vide ne doit pas partir dans l'URL : `?statut=` est
     * interprété par Laravel comme une chaîne vide, pas comme l'absence
     * de filtre — et ne renvoie alors aucune ligne. */
    nettoyer(objet) {
        return Object.fromEntries(
            Object.entries(objet).filter(([, v]) => v !== '' && v !== null && v !== undefined)
        );
    },

    appliquerFiltres() { this.charger(1); },
}));

/* Déconnexion — le jeton est effacé localement même si l'appel échoue :
 * un utilisateur qui clique « se déconnecter » doit être déconnecté,
 * réseau ou pas. */
Alpine.data('session', () => ({
    async deconnecter() {
        try { await api.post('/auth/deconnexion'); } catch { /* sans importance */ }
        jeton.effacer();
        window.location.href = '/';
    },
}));

window.Alpine = Alpine;
Alpine.start();

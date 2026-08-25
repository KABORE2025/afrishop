import Alpine from 'alpinejs';
import { api, jeton, role, espaceDe, ErreurApi, fcfa, dateFr } from './api.js';

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
 * Garde d'accès côté navigateur.
 *
 * Les pages de l'espace vendeur sont servies SANS contrôle côté serveur,
 * et c'est cohérent : elles ne contiennent aucune donnée. Ce sont des
 * coquilles vides que le JavaScript remplit en appelant l'API — laquelle,
 * elle, est protégée par `auth:sanctum` et `role:vendeur`. Un visiteur
 * sans jeton qui atteindrait l'URL ne verrait qu'une page vide.
 *
 * Cette fonction ne protège donc RIEN : elle évite seulement d'afficher
 * un écran vide à quelqu'un qui devrait se connecter. La vraie barrière
 * est l'API, et elle est ailleurs.
 */
window.exigerConnexion = function (roleAttendu = null) {
    if (!jeton.lire()) {
        const retour = encodeURIComponent(window.location.pathname);
        window.location.replace(`/vendeur/connexion?retour=${retour}`);
        return false;
    }

    /*
     * Mauvais espace : on redirige plutôt que de laisser l'écran
     * appeler une API à laquelle le rôle ne donne pas accès. Un
     * administrateur arrivant sur /vendeur y recevrait « aucune
     * boutique rattachée » — un message vrai, mais qui n'explique
     * rien de ce qui se passe réellement.
     */
    const r = role.lire();

    if (roleAttendu === 'vendeur' && r && r !== 'vendeur') {
        window.location.replace(espaceDe(r));
        return false;
    }

    if (roleAttendu === 'admin' && r && !['admin', 'agent'].includes(r)) {
        window.location.replace(espaceDe(r));
        return false;
    }

    return true;
};

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

/*
 * DÉMARRAGE DIFFÉRÉ — et c'est nécessaire, pas cosmétique.
 *
 * Les écrans déclarent leurs propres composants dans un `@push('scripts')`
 * placé en fin de page. Les modules s'exécutent dans l'ordre du document :
 * ce fichier, chargé dans le <head>, tourne AVANT eux. Si Alpine démarrait
 * ici, il aurait déjà parcouru le DOM quand un écran enregistre son
 * composant, et l'écran resterait inerte — sans la moindre erreur en
 * console, ce qui est le pire cas à diagnostiquer.
 *
 * `DOMContentLoaded` se déclenche après l'exécution de tous les modules :
 * chacun a eu le temps de s'enregistrer.
 */
document.addEventListener('DOMContentLoaded', () => Alpine.start());

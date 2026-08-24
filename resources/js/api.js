/*
 * =====================================================================
 *  CLIENT DE L'API AFRISHOP
 * =====================================================================
 *  `fetch` natif, pas de bibliothèque HTTP. Axios pèse une trentaine de
 *  kilo-octets pour ce qu'on fait ici en quarante lignes — et sur une
 *  connexion facturée au mégaoctet, trente kilos par écran ne sont pas
 *  gratuits.
 *
 *  CE FICHIER EXISTE SURTOUT POUR TRAITER LES ERREURS UNE SEULE FOIS.
 *  L'API a quatre réponses non triviales que chaque écran devrait sinon
 *  gérer de son côté — et finirait par gérer différemment :
 *
 *    401  jeton absent ou expiré        → retour à la connexion
 *    403  « aucune boutique rattachée » → candidature en cours d'examen,
 *                                         PAS une erreur à afficher en rouge
 *    422  règle métier ou validation    → message serveur, déjà rédigé en
 *                                         français, à afficher tel quel
 *    419  session expirée               → reconnexion
 * =====================================================================
 */

const CLE_JETON = 'afrishop.jeton';

export const jeton = {
    lire:      () => { try { return localStorage.getItem(CLE_JETON); } catch { return null; } },
    ecrire: (v) => { try { localStorage.setItem(CLE_JETON, v); } catch { /* navigation privée */ } },
    effacer:   () => { try { localStorage.removeItem(CLE_JETON); } catch { /* idem */ } },
};

/**
 * Erreur d'API, avec de quoi décider quoi afficher sans réinspecter le
 * code HTTP dans chaque écran.
 */
export class ErreurApi extends Error {
    constructor(message, { statut, erreurs = {}, sansBoutique = false } = {}) {
        super(message);
        this.name = 'ErreurApi';
        this.statut = statut;
        /** Erreurs de validation, par champ : { prix_ttc_cfa: ['...'] } */
        this.erreurs = erreurs;
        /** Vrai quand le compte n'a pas encore de boutique validée. */
        this.sansBoutique = sansBoutique;
    }
}

async function requete(methode, chemin, corps = null) {
    const entetes = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const t = jeton.lire();
    if (t) entetes['Authorization'] = `Bearer ${t}`;
    if (corps !== null) entetes['Content-Type'] = 'application/json';

    let reponse;
    try {
        reponse = await fetch(`/api${chemin}`, {
            method: methode,
            headers: entetes,
            body: corps !== null ? JSON.stringify(corps) : undefined,
        });
    } catch {
        // Panne réseau : distinguer « pas de réseau » d'une erreur
        // serveur change complètement ce qu'on dit à l'utilisateur.
        throw new ErreurApi(
            'Connexion impossible. Vérifiez votre réseau, puis réessayez.',
            { statut: 0 }
        );
    }

    if (reponse.status === 204) return null;

    const donnees = await reponse.json().catch(() => ({}));

    if (reponse.ok) return donnees;

    const message = donnees.message || 'Une erreur est survenue.';

    if (reponse.status === 401 || reponse.status === 419) {
        jeton.effacer();
        throw new ErreurApi('Votre session a expiré. Reconnectez-vous.', { statut: reponse.status });
    }

    if (reponse.status === 403) {
        /*
         * Cas particulier, et le plus important de ce fichier : un
         * compte vendeur dont la candidature n'est pas encore validée
         * reçoit « Aucune boutique rattachée à ce compte. » Ce n'est pas
         * une erreur, c'est un état normal du parcours. L'afficher en
         * rouge avec « Accès refusé » ferait croire à un rejet.
         */
        const sansBoutique = message.includes('Aucune boutique');
        throw new ErreurApi(message, { statut: 403, sansBoutique });
    }

    if (reponse.status === 422) {
        throw new ErreurApi(message, { statut: 422, erreurs: donnees.errors || {} });
    }

    throw new ErreurApi(message, { statut: reponse.status });
}

export const api = {
    get:    (chemin)         => requete('GET', chemin),
    post:   (chemin, corps)  => requete('POST', chemin, corps ?? {}),
    put:    (chemin, corps)  => requete('PUT', chemin, corps ?? {}),
    patch:  (chemin, corps)  => requete('PATCH', chemin, corps ?? {}),
    delete: (chemin)         => requete('DELETE', chemin),
};

/**
 * Montant en francs CFA.
 * Séparateur d'espace insécable et jamais de décimale : le centime de
 * franc n'existe pas, et l'afficher fait douter du chiffre.
 */
export function fcfa(montant) {
    return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })
        .format(Math.round(montant || 0)) + ' FCFA';
}

/** Date courte, fuseau local. */
export function dateFr(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('fr-FR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
    });
}

<?php

namespace App\Services\Paiement;

use Illuminate\Http\Request;

/**
 * =====================================================================
 *  CONTRAT D'UNE PASSERELLE DE PAIEMENT
 * =====================================================================
 *  Le seul point que le reste de l'application connaît. Brancher
 *  CinetPay, PayDunya ou Flutterwave consiste à écrire une classe qui
 *  implémente cette interface et à l'ajouter au match() de
 *  AppServiceProvider — aucun contrôleur ni service ne change.
 * =====================================================================
 */
interface PaymentGatewayInterface
{
    /**
     * Démarre un encaissement.
     *
     * Un vrai PSP renvoie généralement « en_attente » avec une URL de
     * redirection : le client paie hors du site, et c'est le webhook qui
     * confirmera plus tard. Le driver de secours peut répondre
     * « reussie » immédiatement — c'est ce que fait FakeGateway.
     *
     * @return array{statut: 'reussie'|'en_attente'|'echouee', reference_externe: string, url_paiement: ?string}
     */
    public function initier(int $montantCfa, string $reference, array $meta = []): array;

    /**
     * Vérifie qu'un webhook provient bien du prestataire, pas d'un tiers
     * qui aurait deviné l'URL. Chaque PSP a son propre schéma de
     * signature ; le driver de secours se contente de comparer un secret
     * partagé.
     */
    public function verifierSignatureWebhook(Request $requete): bool;

    /**
     * Traduit la notification du prestataire en une forme commune.
     *
     * POURQUOI CETTE MÉTHODE A ÉTÉ AJOUTÉE À L'INTERFACE. Le contrôleur
     * de webhook lisait directement `reference_externe` et `statut` —
     * un format qu'AUCUN prestataire réel n'envoie. CinetPay poste
     * `cpm_trans_id`, `cpm_error_message`, `cpm_amount`… ; PayDunya
     * autre chose encore. Sans cette traduction, brancher un vrai PSP
     * aurait obligé à réécrire le contrôleur, alors que tout l'intérêt
     * de cette interface est justement qu'il ne bouge pas.
     *
     * Le prestataire est le seul à connaître son propre format : c'est
     * donc à lui de faire la conversion, pas au contrôleur de deviner.
     *
     * Renvoie `null` si la charge utile est inexploitable — le
     * contrôleur répondra alors une erreur, ce qui pousse le PSP à
     * réessayer plutôt qu'à considérer la notification comme acquittée.
     *
     * @return array{reference_externe: string, statut: 'reussie'|'echouee', motif: ?string}|null
     */
    public function lireWebhook(Request $requete): ?array;

    /**
     * Demande au prestataire l'état RÉEL d'un encaissement.
     *
     * POURQUOI C'EST INDISPENSABLE, ET PAS UN CONFORT.
     *
     * Le webhook est le chemin normal, mais il n'arrive pas toujours :
     * une coupure réseau au mauvais moment, un pare-feu qui bloque le
     * port entrant, un serveur redémarré pendant l'appel — et la
     * notification est perdue. Le client a payé, l'argent est chez le
     * prestataire, mais la commande reste « en attente » indéfiniment.
     * Personne ne s'en aperçoit : ni le client, qui a vu son débit, ni
     * le vendeur, qui n'a jamais vu la commande.
     *
     * Cette méthode est la parade : elle permet d'aller CHERCHER
     * l'information au lieu de l'attendre. C'est ce qu'utilise
     * `afrishop:verifier-paiements`.
     *
     * Elle sert aussi à travailler sans webhook du tout — en
     * développement derrière un réseau qui bloque les tunnels, par
     * exemple.
     *
     * @return array{accepte: bool, message: string}|null  null = indéterminé,
     *         ce qui n'est PAS un échec : ne rien décider et redemander plus tard.
     */
    public function verifierPaiement(string $referenceExterne): ?array;
}

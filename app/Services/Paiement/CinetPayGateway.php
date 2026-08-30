<?php

namespace App\Services\Paiement;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * =====================================================================
 *  CINETPAY — passerelle de paiement réelle
 * =====================================================================
 *  CinetPay est un Établissement de Paiement agréé BCEAO. Il agrège
 *  Orange Money, Moov, Wave et la carte bancaire derrière une seule
 *  intégration : c'est ce qui évite de contracter séparément avec
 *  chaque opérateur, chacun avec son API et son délai de reversement.
 *
 *  LE MODÈLE EST ASYNCHRONE, ET TOUT DÉCOULE DE LÀ.
 *  On n'encaisse pas en appelant une API. On demande une URL de
 *  paiement, le client la suit, valide sur SON téléphone, et c'est
 *  CinetPay qui nous rappelle plus tard sur `notify_url`. Entre les
 *  deux, la commande est en attente et rien ne doit être expédié.
 *
 *  TROIS RÈGLES TENUES ICI, ET AUCUNE N'EST FACULTATIVE :
 *
 *  1. LA PAGE DE RETOUR DU NAVIGATEUR NE FAIT JAMAIS FOI.
 *     `return_url` ramène le client sur le site, mais son navigateur
 *     n'est pas une source d'autorité : n'importe qui peut l'ouvrir à
 *     la main. Seul le webhook signé engage.
 *
 *  2. LA NOTIFICATION ELLE-MÊME NE SUFFIT PAS.
 *     CinetPay le dit dans sa propre documentation : après avoir vérifié
 *     la signature, il faut RAPPELER `/v2/payment/check` pour connaître
 *     le vrai statut. Une notification est un signal, pas une preuve —
 *     c'est la parade à quelqu'un qui rejouerait une notification
 *     capturée.
 *
 *  3. LE MONTANT DOIT ÊTRE UN MULTIPLE DE 5.
 *     Contrainte de CinetPay sur toutes ses devises. Ce n'est pas une
 *     bizarrerie : la plus petite pièce en circulation dans l'UEMOA est
 *     de 5 F. On la contrôle AVANT d'appeler l'API, pour donner une
 *     erreur qui dit quoi corriger plutôt qu'un rejet opaque.
 *
 *  Documentation : https://docs.cinetpay.com/api/1.0-fr/checkout/initialisation
 * =====================================================================
 */
class CinetPayGateway implements PaymentGatewayInterface
{
    private const BASE = 'https://api-checkout.cinetpay.com/v2';

    /**
     * Les seize champs du webhook, DANS CET ORDRE, concaténés pour
     * recalculer le HMAC. L'ordre est imposé par CinetPay : le changer,
     * même pour deux champs adjacents, produit une signature différente
     * et fait rejeter toutes les notifications.
     *
     * https://docs.cinetpay.com/api/1.0-fr/checkout/hmac
     */
    private const CHAMPS_HMAC = [
        'cpm_site_id', 'cpm_trans_id', 'cpm_trans_date', 'cpm_amount',
        'cpm_currency', 'signature', 'payment_method', 'cel_phone_num',
        'cpm_phone_prefixe', 'cpm_language', 'cpm_version',
        'cpm_payment_config', 'cpm_page_action', 'cpm_custom',
        'cpm_designation', 'cpm_error_message',
    ];

    public function __construct(
        private string $apiKey,
        private string $siteId,
        private string $secretKey,
        private string $urlNotification,
        private string $urlRetour,
        private string $canaux = 'ALL',
        private string $devise = 'XOF',
    ) {}

    // -----------------------------------------------------------------
    //  ENCAISSEMENT
    // -----------------------------------------------------------------

    public function initier(int $montantCfa, string $reference, array $meta = []): array
    {
        $this->exigerMultipleDeCinq($montantCfa);

        /*
         * IDENTIFIANT UNIQUE PAR TENTATIVE, pas par commande.
         *
         * CinetPay refuse un `transaction_id` déjà vu. Réutiliser la
         * référence de commande ferait donc échouer toute relance après
         * un paiement raté — précisément le cas où le client réessaie.
         * Le suffixe aléatoire garde la référence lisible en préfixe
         * pour le rapprochement manuel, tout en restant unique.
         */
        $idTransaction = $reference . '-' . Str::upper(Str::random(6));

        try {
            $reponse = Http::timeout(20)
                ->acceptJson()
                ->asJson()
                ->post(self::BASE . '/payment', [
                    'apikey'         => $this->apiKey,
                    'site_id'        => $this->siteId,
                    'transaction_id' => $idTransaction,
                    'amount'         => $montantCfa,
                    'currency'       => $this->devise,
                    'description'    => 'Commande Afrishop ' . $reference,
                    'notify_url'     => $this->urlNotification,
                    'return_url'     => $this->urlRetour,
                    'channels'       => $this->canaux,
                    'lang'           => 'fr',
                    /* `metadata` nous revient tel quel dans le webhook,
                     * sous `cpm_custom`. On y met l'identifiant de
                     * commande : si jamais la référence externe devenait
                     * introuvable, il resterait un fil à tirer. */
                    'metadata'       => (string) ($meta['commande_id'] ?? ''),
                ]);
        } catch (\Throwable $e) {
            /*
             * Réseau injoignable. On ne prétend PAS que le paiement a
             * échoué : on ne sait pas si CinetPay a reçu la demande.
             * Dire « échoué » ferait remettre le stock et annuler une
             * commande peut-être bien créée chez eux.
             */
            throw new RuntimeException(
                'CinetPay est injoignable. La commande est enregistrée, le paiement peut être relancé.',
                previous: $e,
            );
        }

        $corps = $reponse->json() ?? [];

        // 201 = transaction créée. Tout le reste est un refus explicite.
        if (($corps['code'] ?? null) !== '201' || empty($corps['data']['payment_url'])) {
            Log::error('CinetPay a refusé l\'initialisation.', [
                'reference' => $reference,
                'code'      => $corps['code'] ?? null,
                'message'   => $corps['message'] ?? null,
                'detail'    => $corps['description'] ?? null,
            ]);

            return [
                'statut'            => 'echouee',
                'reference_externe' => $idTransaction,
                'url_paiement'      => null,
            ];
        }

        /*
         * TOUJOURS « en_attente », jamais « reussie ».
         *
         * À cet instant, le client n'a rien payé : il n'a même pas encore
         * ouvert la page. Répondre « reussie » créditerait le grand livre
         * d'une vente qui n'existe pas. Seul le webhook, confirmé par un
         * appel à /check, fait passer la commande à « encaisse ».
         */
        return [
            'statut'            => 'en_attente',
            'reference_externe' => $idTransaction,
            'url_paiement'      => $corps['data']['payment_url'],
        ];
    }

    // -----------------------------------------------------------------
    //  WEBHOOK
    // -----------------------------------------------------------------

    /**
     * Vérifie le HMAC de l'en-tête `x-token`.
     *
     * `hash_equals` et non `===` : la comparaison de chaînes ordinaire
     * s'arrête au premier caractère différent, et le temps qu'elle met
     * renseigne un attaquant sur le nombre de caractères déjà justes.
     * C'est la faille par laquelle on reconstitue une signature octet
     * par octet.
     */
    public function verifierSignatureWebhook(Request $requete): bool
    {
        $recu = (string) $requete->header('x-token', '');

        if ($recu === '' || $this->secretKey === '') {
            Log::warning('Webhook CinetPay sans x-token, ou clé secrète non configurée.');

            return false;
        }

        $chaine = '';
        foreach (self::CHAMPS_HMAC as $champ) {
            // Un champ absent compte comme une chaîne vide : c'est ce que
            // fait la concaténation de référence de CinetPay.
            $chaine .= (string) $requete->input($champ, '');
        }

        return hash_equals(hash_hmac('sha256', $chaine, $this->secretKey), $recu);
    }

    /**
     * Traduit la notification, PUIS vérifie le statut auprès de CinetPay.
     *
     * La signature prouve que le message vient bien de CinetPay ; elle
     * ne prouve pas que le paiement a abouti — un message authentique
     * peut être rejoué. C'est `/v2/payment/check` qui tranche, et c'est
     * la marche à suivre que CinetPay documente lui-même.
     */
    public function lireWebhook(Request $requete): ?array
    {
        $idTransaction = (string) $requete->input('cpm_trans_id', '');

        if ($idTransaction === '') {
            Log::warning('Webhook CinetPay sans cpm_trans_id.');

            return null;
        }

        $etat = $this->verifierPaiement($idTransaction);

        if ($etat === null) {
            // Statut indéterminé : ne rien décider. Renvoyer `null` fait
            // répondre une erreur au PSP, qui réessaiera — bien préférable
            // à finaliser une commande sur une supposition.
            return null;
        }

        return [
            'reference_externe' => $idTransaction,
            'statut'            => $etat['accepte'] ? 'reussie' : 'echouee',
            'motif'             => $etat['message'],
        ];
    }

    /**
     * Interroge CinetPay sur l'état réel d'une transaction.
     *
     * Utilisable aussi hors webhook : pour une commande restée en
     * attente parce que la notification s'est perdue — un réseau coupé
     * pendant l'appel suffit — c'est le seul moyen de savoir si l'argent
     * est arrivé.
     *
     * @return array{accepte: bool, message: string}|null  null = indéterminé
     */
    public function verifierPaiement(string $idTransaction): ?array
    {
        try {
            $reponse = Http::timeout(20)->acceptJson()->asJson()
                ->post(self::BASE . '/payment/check', [
                    'apikey'         => $this->apiKey,
                    'site_id'        => $this->siteId,
                    'transaction_id' => $idTransaction,
                ]);
        } catch (\Throwable $e) {
            Log::error('Vérification CinetPay injoignable.', [
                'transaction' => $idTransaction,
                'erreur'      => $e->getMessage(),
            ]);

            return null;
        }

        $corps = $reponse->json() ?? [];
        $statut = $corps['data']['status'] ?? null;

        /*
         * Trois cas, et le troisième est le plus important.
         *
         *   ACCEPTED  encaissé, on peut créditer ;
         *   REFUSED   refusé ou annulé, on remet le stock ;
         *   autre     PENDING, WAITING_FOR_CUSTOMER, ou statut inconnu →
         *             INDÉTERMINÉ. Le traiter comme un échec annulerait
         *             une commande dont le client est encore en train de
         *             saisir son code sur son téléphone.
         */
        if ($statut === 'ACCEPTED') {
            return ['accepte' => true, 'message' => 'Paiement confirmé par CinetPay.'];
        }

        if ($statut === 'REFUSED') {
            return [
                'accepte' => false,
                'message' => 'Paiement refusé : ' . ($corps['message'] ?? 'motif non précisé') . '.',
            ];
        }

        Log::info('Transaction CinetPay encore indéterminée.', [
            'transaction' => $idTransaction,
            'statut'      => $statut,
            'code'        => $corps['code'] ?? null,
        ]);

        return null;
    }

    // -----------------------------------------------------------------

    /**
     * CinetPay refuse tout montant qui n'est pas un multiple de 5.
     *
     * Contrôlé ici plutôt que laissé au refus de l'API : un rejet de
     * CinetPay arrive sous forme de code d'erreur, au moment du paiement,
     * et ne dit pas quoi corriger. Cette exception, elle, nomme le
     * montant fautif.
     *
     * LA CORRECTION DURABLE N'EST PAS D'ARRONDIR ICI. Arrondir ferait
     * payer au client autre chose que le total de sa commande, et le
     * grand livre affirmerait un montant que la banque contredirait. Ce
     * sont les PRIX et les FRAIS DE LIVRAISON qui doivent être des
     * multiples de 5 — ce qui est de toute façon l'usage, la plus petite
     * pièce en circulation valant 5 F.
     */
    private function exigerMultipleDeCinq(int $montantCfa): void
    {
        if ($montantCfa % 5 !== 0) {
            throw new RuntimeException(
                "Montant de {$montantCfa} FCFA refusé : CinetPay n'accepte que des multiples de 5. "
                . 'Vérifiez les prix des articles et les frais de livraison.'
            );
        }
    }
}

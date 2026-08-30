<?php

namespace App\Services\Paiement;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * =====================================================================
 *  PAYDUNYA — passerelle de paiement réelle
 * =====================================================================
 *  PayDunya est, comme CinetPay, un Établissement de Paiement agréé
 *  BCEAO. Une seule raison de le brancher en premier : IL A UN VRAI BAC
 *  À SABLE. On obtient des clés de test en s'inscrivant, on encaisse
 *  des paiements fictifs, et toute la chaîne — page de paiement,
 *  notification, écriture au grand livre, séquestre — se vérifie
 *  AVANT le moindre engagement commercial et sans qu'un franc bouge.
 *
 *  Le passage en production ne change qu'une chose : `PAYDUNYA_MODE`
 *  passe de `test` à `live`, ce qui bascule l'URL de base et les clés.
 *  Aucune ligne de code.
 *
 *  DEUX AVERTISSEMENTS HONNÊTES SUR CETTE INTÉGRATION :
 *
 *  1. LE `hash` DE LA NOTIFICATION NE PROUVE PAS GRAND-CHOSE.
 *     PayDunya envoie le SHA-512 de la clé maître. C'est une valeur
 *     CONSTANTE : quiconque a vu passer une notification peut la
 *     rejouer à l'identique. On la vérifie quand même — elle écarte le
 *     bruit — mais la vraie autorité est l'appel à `/confirm`, qui est
 *     authentifié par les clés et donne l'état réel de la facture.
 *
 *  2. LE MODE EST VÉRIFIÉ À LA RÉCEPTION.
 *     La notification porte `mode: test|live`. Une notification de test
 *     acceptée par une installation en production créditerait une vente
 *     jamais payée. On refuse tout ce qui ne correspond pas au mode
 *     configuré.
 *
 *  Documentation : https://developers.paydunya.com/doc/FR/http_json
 * =====================================================================
 */
class PayDunyaGateway implements PaymentGatewayInterface
{
    private const BASE_TEST = 'https://app.paydunya.com/sandbox-api/v1';
    private const BASE_LIVE = 'https://app.paydunya.com/api/v1';

    public function __construct(
        private string $masterKey,
        private string $privateKey,
        private string $token,
        private string $urlNotification,
        private string $urlRetour,
        private string $urlAnnulation,
        private string $nomBoutique,
        private string $mode = 'test',
    ) {}

    private function base(): string
    {
        return $this->mode === 'live' ? self::BASE_LIVE : self::BASE_TEST;
    }

    /**
     * Les trois clés voyagent en en-tête, jamais dans le corps.
     * `PAYDUNYA-PRIVATE-KEY` est préfixée `test_` en bac à sable : c'est
     * PayDunya qui l'impose, et se tromper de paire de clés produit un
     * refus d'authentification, pas un paiement mal dirigé.
     */
    private function entetes(): array
    {
        return [
            'PAYDUNYA-MASTER-KEY'  => $this->masterKey,
            'PAYDUNYA-PRIVATE-KEY' => $this->privateKey,
            'PAYDUNYA-TOKEN'       => $this->token,
        ];
    }

    // -----------------------------------------------------------------
    //  ENCAISSEMENT
    // -----------------------------------------------------------------

    public function initier(int $montantCfa, string $reference, array $meta = []): array
    {
        try {
            $reponse = Http::timeout(20)
                ->withHeaders($this->entetes())
                ->acceptJson()->asJson()
                ->post($this->base() . '/checkout-invoice/create', [
                    'invoice' => [
                        'total_amount' => $montantCfa,
                        'description'  => 'Commande Afrishop ' . $reference,
                    ],
                    'store' => [
                        'name' => $this->nomBoutique,
                    ],
                    /*
                     * `custom_data` nous revient tel quel dans la
                     * notification. On y met la référence de commande :
                     * le jeton PayDunya est la clé technique, mais c'est
                     * cette référence qui permet à un humain de
                     * rapprocher une ligne dans un relevé.
                     */
                    'custom_data' => [
                        'reference'   => $reference,
                        'commande_id' => (string) ($meta['commande_id'] ?? ''),
                    ],
                    'actions' => [
                        'callback_url' => $this->urlNotification,
                        'return_url'   => $this->urlRetour,
                        'cancel_url'   => $this->urlAnnulation,
                    ],
                ]);
        } catch (\Throwable $e) {
            /*
             * Réseau injoignable. On ne dit PAS « échoué » : on ignore
             * si PayDunya a reçu la demande. Annoncer un échec remettrait
             * le stock et annulerait une commande peut-être bien créée
             * chez eux.
             */
            throw new RuntimeException(
                'PayDunya est injoignable. La commande est enregistrée, le paiement peut être relancé.',
                previous: $e,
            );
        }

        $corps = $reponse->json() ?? [];

        if (($corps['response_code'] ?? null) !== '00' || empty($corps['token'])) {
            Log::error('PayDunya a refusé la création de la facture.', [
                'reference' => $reference,
                'code'      => $corps['response_code'] ?? null,
                'texte'     => $corps['response_text'] ?? null,
            ]);

            return [
                'statut'            => 'echouee',
                'reference_externe' => 'PD-ECHEC-' . $reference,
                'url_paiement'      => null,
            ];
        }

        /*
         * TOUJOURS « en_attente ».
         * La facture est créée, le client n'a rien payé — il n'a même
         * pas ouvert la page. Répondre « reussie » créditerait le grand
         * livre d'une vente inexistante.
         */
        return [
            'statut'            => 'en_attente',
            'reference_externe' => $corps['token'],
            'url_paiement'      => $corps['response_text'] ?? null,
        ];
    }

    // -----------------------------------------------------------------
    //  NOTIFICATION (IPN)
    // -----------------------------------------------------------------

    /**
     * Vérifie le `hash` de la notification.
     *
     * PayDunya envoie le SHA-512 de la clé maître — une valeur
     * CONSTANTE, identique pour toutes les notifications. Elle écarte
     * le bruit et les appels au hasard, mais quiconque a intercepté une
     * seule notification peut la rejouer.
     *
     * C'est pour cette raison exacte que `lireWebhook()` ne se contente
     * jamais de cette vérification et rappelle `/confirm`. Ne pas
     * confondre « authentique » et « payé ».
     */
    public function verifierSignatureWebhook(Request $requete): bool
    {
        $donnees = $this->charge($requete);
        $recu    = (string) ($donnees['hash'] ?? '');

        if ($recu === '' || $this->masterKey === '') {
            Log::warning('Notification PayDunya sans hash, ou clé maître non configurée.');

            return false;
        }

        return hash_equals(hash('sha512', $this->masterKey), $recu);
    }

    /**
     * Traduit la notification, PUIS demande à PayDunya l'état réel.
     */
    public function lireWebhook(Request $requete): ?array
    {
        $donnees = $this->charge($requete);
        $jeton   = (string) ($donnees['invoice']['token'] ?? '');

        if ($jeton === '') {
            Log::warning('Notification PayDunya sans jeton de facture.');

            return null;
        }

        /*
         * GARDE DE MODE. Une notification `test` reçue par une
         * installation `live` créditerait une vente que personne n'a
         * payée. L'inverse est bénin mais tout aussi révélateur d'une
         * erreur de configuration.
         */
        $modeRecu = (string) ($donnees['mode'] ?? '');
        if ($modeRecu !== '' && $modeRecu !== $this->mode) {
            Log::error('Notification PayDunya dans le mauvais mode — ignorée.', [
                'attendu' => $this->mode,
                'recu'    => $modeRecu,
                'jeton'   => $jeton,
            ]);

            return null;
        }

        $etat = $this->verifierPaiement($jeton);

        if ($etat === null) {
            // Indéterminé : ne rien décider. Le contrôleur répondra une
            // erreur, PayDunya réessaiera.
            return null;
        }

        return [
            'reference_externe' => $jeton,
            'statut'            => $etat['accepte'] ? 'reussie' : 'echouee',
            'motif'             => $etat['message'],
        ];
    }

    /**
     * Interroge PayDunya sur l'état réel d'une facture.
     *
     * Utilisable hors notification : quand une notification s'est perdue
     * — une coupure réseau au mauvais moment suffit — c'est le seul
     * moyen de savoir si l'argent est arrivé.
     *
     * @return array{accepte: bool, message: string}|null  null = indéterminé
     */
    public function verifierPaiement(string $jeton): ?array
    {
        try {
            $reponse = Http::timeout(20)
                ->withHeaders($this->entetes())
                ->acceptJson()
                ->get($this->base() . '/checkout-invoice/confirm/' . $jeton);
        } catch (\Throwable $e) {
            Log::error('Vérification PayDunya injoignable.', [
                'jeton'  => $jeton,
                'erreur' => $e->getMessage(),
            ]);

            return null;
        }

        $corps  = $reponse->json() ?? [];
        $statut = $corps['status'] ?? null;

        /*
         * Quatre statuts, trois traitements.
         *
         *   completed             encaissé ;
         *   cancelled | failed    abandonné ou refusé → on remet le stock ;
         *   pending (ou autre)    INDÉTERMINÉ. Le client est peut-être en
         *                         train de saisir son code. Le traiter
         *                         comme un échec annulerait sa commande
         *                         sous ses yeux.
         */
        if ($statut === 'completed') {
            return ['accepte' => true, 'message' => 'Paiement confirmé par PayDunya.'];
        }

        if (in_array($statut, ['cancelled', 'failed'], true)) {
            return [
                'accepte' => false,
                'message' => 'Paiement ' . ($statut === 'cancelled' ? 'annulé' : 'refusé')
                    . ' : ' . ($corps['fail_reason'] ?? 'motif non précisé') . '.',
            ];
        }

        Log::info('Facture PayDunya encore indéterminée.', ['jeton' => $jeton, 'statut' => $statut]);

        return null;
    }

    // -----------------------------------------------------------------

    /**
     * La charge utile de la notification.
     *
     * PayDunya poste en `application/x-www-form-urlencoded` un unique
     * champ `data` contenant du JSON — pas un corps JSON. Selon la
     * version, `data` arrive déjà décodé en tableau ou sous forme de
     * chaîne ; on accepte les deux plutôt que de parier sur l'une.
     */
    private function charge(Request $requete): array
    {
        $data = $requete->input('data');

        if (is_array($data)) {
            return $data;
        }

        if (is_string($data) && $data !== '') {
            return json_decode($data, true) ?: [];
        }

        // Dernier recours : certaines intégrations postent le JSON à la
        // racine du corps.
        return $requete->all();
    }
}

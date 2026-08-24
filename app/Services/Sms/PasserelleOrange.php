<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * =====================================================================
 *  PASSERELLE ORANGE — API SMS Burkina Faso
 * =====================================================================
 *  https://developer.orange.com/apis/sms-bf
 *
 *  POURQUOI ORANGE PLUTÔT QU'UN AGRÉGATEUR INTERNATIONAL
 *  Le prix. Orange Burkina vend 1 000 SMS pour 8 000 F, soit 8 F pièce.
 *  Un agrégateur international facture autour de 0,22 USD, soit environ
 *  133 F — seize fois plus. Sur une commande qui génère deux ou trois
 *  messages, la différence n'est pas une ligne de frais : c'est une
 *  part de la commission.
 *
 *  DEUX CONTRAINTES DE L'API, à connaître avant de dimensionner
 *   · 5 SMS par seconde maximum. C'est pour cela que l'envoi passe par
 *     une file drainée par une commande planifiée, jamais par la
 *     requête HTTP du client.
 *   · Les paquets ont une DATE DE VALIDITÉ (30 jours pour le Silver).
 *     Un solde non consommé expire. Le suivi de solde ci-dessous existe
 *     pour éviter de découvrir le problème un vendredi soir.
 *
 *  ⚠ POINT À CONFIRMER AVANT LA MISE EN PRODUCTION
 *  Vérifier auprès d'Orange que ce contrat livre bien vers les
 *  abonnés Moov, Telecel et Airtel, et pas uniquement vers les numéros
 *  Orange. Orange représente environ 40 % du parc burkinabè : si la
 *  livraison est limitée à son propre réseau, six clients sur dix ne
 *  reçoivent pas leur code de livraison, et il faut un second
 *  fournisseur en complément.
 * =====================================================================
 */
class PasserelleOrange implements PasserelleSmsInterface
{
    private const URL_TOKEN = 'https://api.orange.com/oauth/v3/token';
    private const URL_ENVOI = 'https://api.orange.com/smsmessaging/v1/outbound/%s/requests';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        /** Numéro émetteur fourni par Orange, format international : +226… */
        private string $adresseExpediteur,
        /** Nom affiché au destinataire. 11 caractères alphanumériques maximum. */
        private string $nomExpediteur,
        private int $coutUnitaireCfa,
    ) {}

    public function envoyer(string $telephone, string $message): array
    {
        $numero = $this->normaliser($telephone);

        if ($numero === null) {
            // Un numéro invalide n'est pas une panne : c'est une donnée
            // fausse, et elle doit être visible dans le journal des
            // notifications plutôt que relancée indéfiniment.
            return ['statut' => 'echoue', 'reference_externe' => null,
                    'erreur' => 'Numéro invalide : ' . $telephone];
        }

        try {
            $jeton = $this->jeton();
        } catch (\Throwable $e) {
            return ['statut' => 'echoue', 'reference_externe' => null,
                    'erreur' => 'Authentification Orange impossible : ' . $e->getMessage()];
        }

        $url = sprintf(self::URL_ENVOI, rawurlencode('tel:' . $this->adresseExpediteur));

        $reponse = Http::withToken($jeton)
            ->timeout(15)
            ->retry(2, 500, throw: false)
            ->post($url, [
                'outboundSMSMessageRequest' => [
                    'address'                => 'tel:' . $numero,
                    'senderAddress'          => 'tel:' . $this->adresseExpediteur,
                    'senderName'             => $this->nomExpediteur,
                    'outboundSMSTextMessage' => ['message' => $message],
                ],
            ]);

        if ($reponse->failed()) {
            return [
                'statut'            => 'echoue',
                'reference_externe' => null,
                // Tronqué : la colonne `erreur` fait 255 caractères et
                // une réponse d'API peut être bien plus longue.
                'erreur'            => mb_substr('HTTP ' . $reponse->status() . ' — ' . $reponse->body(), 0, 250),
            ];
        }

        return [
            'statut'            => 'envoye',
            'reference_externe' => $reponse->json('outboundSMSMessageRequest.resourceURL'),
            'erreur'            => null,
        ];
    }

    public function nom(): string { return 'orange-bf'; }

    public function coutUnitaireCfa(): int { return $this->coutUnitaireCfa; }

    /**
     * Jeton OAuth, valable une heure côté Orange. Mis en cache 55
     * minutes : redemander un jeton à chaque SMS ferait deux appels
     * réseau au lieu d'un, et la limite de 5 envois par seconde serait
     * atteinte deux fois plus vite.
     */
    private function jeton(): string
    {
        return Cache::remember('sms.orange.jeton', now()->addMinutes(55), function () {
            $reponse = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->timeout(15)
                ->post(self::URL_TOKEN, ['grant_type' => 'client_credentials'])
                ->throw();

            return $reponse->json('access_token');
        });
    }

    /**
     * Normalise un numéro burkinabè au format international.
     *
     * Les numéros saisis arrivent sous toutes les formes : « 70 12 34 56 »,
     * « 0022670123456 », « +226 70123456 ». Le mobile burkinabè fait
     * huit chiffres. Refuser plutôt que deviner : envoyer un code de
     * livraison à un mauvais numéro est pire que ne pas l'envoyer.
     */
    private function normaliser(string $telephone): ?string
    {
        $n = preg_replace('/[^0-9+]/', '', $telephone);
        $n = preg_replace('/^(\+226|00226|226)/', '', $n);

        return preg_match('/^[0-9]{8}$/', $n) ? '+226' . $n : null;
    }
}

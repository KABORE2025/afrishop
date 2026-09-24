<?php

namespace App\Services;

use App\Models\SousCommande;
use RuntimeException;

/**
 * =====================================================================
 *  RENVOI DU CODE DE REMISE (livraison ou retrait)
 * =====================================================================
 *  Un seul chemin, que la demande vienne du client (page de commande)
 *  ou d'un admin (console) :
 *
 *    · TOUJOURS le code déjà généré, jamais un nouveau. En émettre un
 *      second invaliderait celui que le client a peut-être confié à un
 *      proche venu récupérer le colis à sa place ;
 *    · TOUJOURS vers le téléphone ENREGISTRÉ sur la commande, jamais
 *      vers un numéro saisi : connaître la référence ne suffit donc
 *      pas à récupérer le code.
 * =====================================================================
 */
class CodeRemiseService
{
    public function __construct(private NotificationService $notifications) {}

    /** Un code attend-il d'être présenté ? (colis en route ou prêt au comptoir) */
    public function codeEnAttente(SousCommande $sc): bool
    {
        return $this->raisonRefus($sc) === null;
    }

    public function raisonRefus(SousCommande $sc): ?string
    {
        $sc->loadMissing(['expedition', 'commande']);

        if ($sc->commande->mode_livraison === 'retrait_boutique') {
            return match (true) {
                $sc->code_retrait === null => 'Aucun code de retrait à renvoyer.',
                $sc->statut === 'livree'   => 'Cette commande a déjà été retirée.',
                default                    => null,
            };
        }

        $expedition = $sc->expedition;

        return match (true) {
            ! $expedition || $expedition->code_livraison === null => 'Aucun code de livraison à renvoyer.',
            $expedition->statut === 'livree'                      => 'Cette commande a déjà été livrée.',
            default                                               => null,
        };
    }

    public function renvoyer(SousCommande $sc): void
    {
        if ($raison = $this->raisonRefus($sc)) {
            throw new RuntimeException($raison);
        }

        if ($sc->commande->mode_livraison === 'retrait_boutique') {
            $this->notifications->envoyer('code_retrait', 'sms', [
                'reference' => $sc->reference,
                'code'      => $sc->code_retrait,
                'lien'      => route('commande.confirmee', $sc->commande->reference),
            ], telephone: $sc->commande->client_telephone);

            $sc->update(['code_retrait_envoye_le' => now()]);

            return;
        }

        $this->notifications->envoyer('code_livraison', 'sms', [
            'client_nom' => $sc->commande->client_nom,
            'reference'  => $sc->reference,
            'code'       => $sc->expedition->code_livraison,
            'lien'       => route('commande.confirmee', $sc->commande->reference),
        ], telephone: $sc->commande->client_telephone);
    }
}

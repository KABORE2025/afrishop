<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Paiement\PaiementCommandeService;
use App\Services\Paiement\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 *  WEBHOOK PAIEMENT — point d'entrée pour un prestataire réel
 * =====================================================================
 *  C'est LE point d'extension attendu quand un PSP sera sous contrat :
 *  brancher CinetPay/PayDunya/Flutterwave consiste à écrire son
 *  PaymentGatewayInterface, éventuellement à adapter la lecture de la
 *  charge utile ci-dessous à son format propre — et rien d'autre.
 *
 *  Le webhook fait foi, jamais la page de retour du navigateur du
 *  client : c'est pour ça que ce point d'entrée existe, et pas seulement
 *  un contrôle côté client après redirection.
 *
 *  L'idempotence est garantie par PaiementCommandeService, pas ici :
 *  les PSP rejouent leurs webhooks, et un webhook rejoué ne doit jamais
 *  créditer une vente deux fois.
 * =====================================================================
 */
class PaiementWebhookController extends Controller
{
    public function __construct(
        private PaymentGatewayInterface $passerelle,
        private PaiementCommandeService $paiement,
    ) {}

    public function recevoir(Request $r, string $prestataire): JsonResponse
    {
        if (! $this->passerelle->verifierSignatureWebhook($r)) {
            return response()->json(['message' => 'Signature invalide.'], 403);
        }

        /*
         * LA TRADUCTION EST FAITE PAR LA PASSERELLE, PAS ICI.
         *
         * Ce contrôleur lisait auparavant `reference_externe` et
         * `statut` — un format qu'aucun prestataire réel n'envoie.
         * CinetPay poste `cpm_trans_id`, `cpm_error_message`,
         * `cpm_amount`… Chaque PSP connaît son propre format ; c'est
         * donc à lui de le convertir, et à ce contrôleur de ne plus rien
         * savoir du prestataire branché.
         */
        $data = $this->passerelle->lireWebhook($r);

        if ($data === null) {
            /*
             * Charge utile inexploitable, ou statut encore indéterminé
             * chez le prestataire. On répond une erreur EXPRÈS : la
             * plupart des PSP réessaient tant qu'ils n'ont pas reçu un
             * 2xx. Acquitter ici ferait perdre définitivement la
             * notification d'un paiement en cours de validation.
             */
            return response()->json(['message' => 'Notification inexploitable pour le moment.'], 422);
        }

        $tx = $this->paiement->trouverParReferenceExterne($data['reference_externe']);

        if (! $tx) {
            // 404, pas 200 : un PSP qui reçoit autre chose qu'un succès
            // réessaiera, ce qui est le comportement voulu tant que la
            // transaction n'est pas retrouvée.
            return response()->json(['message' => 'Transaction inconnue.'], 404);
        }

        if ($data['statut'] === 'reussie') {
            $this->paiement->finaliserReussie($tx);
        } else {
            $this->paiement->finaliserEchouee($tx, $data['motif'] ?? "Rejet signalé par {$prestataire}.");
        }

        return response()->json(['recu' => true]);
    }
}

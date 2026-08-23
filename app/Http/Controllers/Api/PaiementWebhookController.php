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

        $data = $r->validate([
            'reference_externe' => ['required', 'string'],
            'statut'             => ['required', 'in:reussie,echouee'],
            'motif'              => ['nullable', 'string'],
        ]);

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

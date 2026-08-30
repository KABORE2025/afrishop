<?php

namespace App\Services\Paiement;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * =====================================================================
 *  PASSERELLE DE SECOURS — driver « fake »
 * =====================================================================
 *  Liée par défaut (PSP_DRIVER=fake dans .env.example) tant qu'aucun
 *  contrat n'est signé avec un prestataire réel. Simule un encaissement
 *  instantané et réussi, pour que le parcours de commande en ligne reste
 *  développable et testable de bout en bout — y compris le webhook, qui
 *  fonctionne réellement avec ce driver.
 * =====================================================================
 */
class FakeGateway implements PaymentGatewayInterface
{
    public function initier(int $montantCfa, string $reference, array $meta = []): array
    {
        return [
            'statut'            => 'reussie',
            'reference_externe' => 'FAKE-' . $reference . '-' . Str::random(8),
            'url_paiement'      => null,
        ];
    }

    /**
     * Aucun secret à vérifier : ce driver ne reçoit jamais de webhook
     * d'un vrai prestataire. Renvoyer vrai uniquement hors production
     * évite qu'un webhook non signé soit accepté par erreur si ce driver
     * restait actif en production.
     */
    public function verifierSignatureWebhook(Request $requete): bool
    {
        return ! app()->isProduction();
    }

    /**
     * Format « maison », celui des tests : le webhook de ce driver est
     * appelé à la main ou par la suite de tests, avec exactement les
     * trois champs attendus.
     */
    public function lireWebhook(Request $requete): ?array
    {
        $donnees = $requete->validate([
            'reference_externe' => ['required', 'string'],
            'statut'            => ['required', 'in:reussie,echouee'],
            'motif'             => ['nullable', 'string'],
        ]);

        return [
            'reference_externe' => $donnees['reference_externe'],
            'statut'            => $donnees['statut'],
            'motif'             => $donnees['motif'] ?? null,
        ];
    }

    /**
     * Ce driver encaisse instantanément : une transaction connue de lui
     * est forcément acceptée. Renvoyer « indéterminé » ferait tourner
     * la commande de vérification en boucle sans jamais rien conclure.
     */
    public function verifierPaiement(string $referenceExterne): ?array
    {
        return ['accepte' => true, 'message' => 'Encaissement simulé (driver fake).'];
    }
}

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
        // `afrishop.psp.fake.initiation` permet aux tests de simuler un
        // paiement resté en attente (client qui ferme la page) ou refusé.
        $statut = (string) config('afrishop.psp.fake.initiation', 'reussie');

        return [
            'statut'            => in_array($statut, ['reussie', 'en_attente', 'echouee'], true) ? $statut : 'reussie',
            'reference_externe' => 'FAKE-' . $reference . '-' . Str::random(8),
            'url_paiement'      => $statut === 'en_attente' ? url('/paiement-simule') : null,
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
        // `afrishop.psp.fake.verification` : « accepte », « refuse » ou
        // « indetermine » (le prestataire ne sait pas encore).
        return match ((string) config('afrishop.psp.fake.verification', 'accepte')) {
            'indetermine' => null,
            'refuse'      => ['accepte' => false, 'message' => 'Refus simulé (driver fake).'],
            default       => ['accepte' => true, 'message' => 'Encaissement simulé (driver fake).'],
        };
    }
}

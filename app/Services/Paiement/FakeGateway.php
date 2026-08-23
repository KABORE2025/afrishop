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
}

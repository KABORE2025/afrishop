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
}

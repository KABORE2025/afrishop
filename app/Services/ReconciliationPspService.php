<?php

namespace App\Services;

use App\Models\ReconciliationPsp;
use App\Models\TransactionPaiement;
use Illuminate\Support\Carbon;

/**
 * =====================================================================
 *  RÉCONCILIATION AVEC LE PRESTATAIRE DE PAIEMENT
 * =====================================================================
 *  Un rapprochement a deux volets : ce que dit LE PRESTATAIRE, et ce que
 *  dit LA PLATEFORME. Sans connecteur PSP réel, seul le second est
 *  calculable automatiquement — d'où deux méthodes distinctes :
 *
 *   · enregistrerLocal() calcule le volet local (transactions_paiement)
 *     et peut tourner seule, chaque jour, sans rien demander à personne ;
 *   · rapprocher() confronte ce volet local aux chiffres du prestataire,
 *     une fois qu'on dispose d'un export ou d'une API pour les obtenir —
 *     ce qui n'existe pas encore (voir docs/registre-des-manques.md, D3).
 *
 *  Tant que rapprocher() n'a pas été appelée pour une date donnée,
 *  l'arrêté reste « en_cours » : c'est un signal honnête, pas un faux
 *  « conforme ».
 * =====================================================================
 */
class ReconciliationPspService
{
    public function enregistrerLocal(int $prestataireId, Carbon $date): ReconciliationPsp
    {
        $existant = ReconciliationPsp::where('prestataire_id', $prestataireId)
            ->where('date_arrete', $date->toDateString())
            ->first();

        // Un arrêté déjà rapproché (conforme, écart ou régularisé) ne
        // doit plus être recalculé automatiquement : ce serait écraser
        // une décision humaine par un recalcul silencieux.
        if ($existant && $existant->statut !== 'en_cours') {
            return $existant;
        }

        $agrege = TransactionPaiement::where('prestataire_id', $prestataireId)
            ->where('sens', 'encaissement')
            ->where('statut', 'reussie')
            ->whereDate('finalisee_le', $date->toDateString())
            ->selectRaw('COUNT(*) AS nb, COALESCE(SUM(montant_cfa), 0) AS montant')
            ->first();

        return ReconciliationPsp::updateOrCreate(
            ['prestataire_id' => $prestataireId, 'date_arrete' => $date->toDateString()],
            [
                'nb_operations_local' => (int) $agrege->nb,
                'montant_local_cfa'   => (int) $agrege->montant,
                'statut'              => 'en_cours',
            ]
        );
    }

    /**
     * Confronte le volet local aux chiffres communiqués par le
     * prestataire (export manuel, ou futur appel API). Pas encore
     * appelée automatiquement : voir le commentaire de classe.
     */
    public function rapprocher(ReconciliationPsp $arrete, int $nbOperationsPsp, int $montantPspCfa): ReconciliationPsp
    {
        $ecart = $montantPspCfa - $arrete->montant_local_cfa;
        $conforme = $ecart === 0 && $nbOperationsPsp === $arrete->nb_operations_local;

        $arrete->update([
            'nb_operations_psp' => $nbOperationsPsp,
            'montant_psp_cfa'   => $montantPspCfa,
            'ecart_montant_cfa' => $ecart,
            'statut'            => $conforme ? 'conforme' : 'ecart',
        ]);

        return $arrete;
    }
}

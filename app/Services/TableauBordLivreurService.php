<?php

namespace App\Services;

use App\Models\SousCommande;
use App\Models\Utilisateur;

/**
 * =====================================================================
 *  INDICATEURS DE L'ESPACE LIVREUR
 * =====================================================================
 *  AUCUN MONTANT ICI, DÉLIBÉRÉMENT. Le livreur n'est aujourd'hui payé
 *  par personne pour aucune livraison — ce chantier est distinct et pas
 *  encore décidé. Afficher un « gains » à zéro partout serait pire que
 *  ne rien afficher : ça laisserait croire à une rémunération qui
 *  n'existe pas. Seuls des compteurs d'activité, purement opérationnels.
 * =====================================================================
 */
class TableauBordLivreurService
{
    public function pour(Utilisateur $livreur): array
    {
        $base = fn () => SousCommande::whereHas('expedition', fn ($q) => $q->where('livreur_id', $livreur->id));

        return [
            'compteurs' => [
                'en_cours'          => (int) $base()->where('statut', 'expediee')->count(),
                'livrees_aujourdhui'=> (int) $base()->where('statut', 'livree')
                    ->whereDate('livre_le', today())->count(),
                'livrees_semaine'   => (int) $base()->where('statut', 'livree')
                    ->where('livre_le', '>=', now()->subDays(7))->count(),
                /*
                 * Compté depuis le journal, pas une colonne : un échec
                 * n'est jamais qu'un événement de plus dans l'historique
                 * de la sous-commande (voir LivreurController::signalerEchec()).
                 */
                'echecs_semaine'    => (int) $base()->whereHas('evenements', fn ($q) => $q
                    ->where('type', 'livraison_echouee')->where('cree_le', '>=', now()->subDays(7)))->count(),
                'retours_semaine'   => (int) $base()->whereHas('expedition',
                    fn ($q) => $q->where('livreur_id', $livreur->id)->where('statut', 'retour_expediteur'))
                    ->where('modifie_le', '>=', now()->subDays(7))->count(),
            ],
        ];
    }
}

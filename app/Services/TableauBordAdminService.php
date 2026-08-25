<?php

namespace App\Services;

use App\Models\Candidature;
use App\Models\CantonnementJournalier;
use App\Models\Litige;
use App\Models\Produit;
use App\Models\Reversement;
use App\Models\SousCommande;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 *  INDICATEURS DE LA CONSOLE AFRISHOP
 * =====================================================================
 *  Ce tableau de bord ne mesure pas la performance commerciale : il
 *  liste CE QUI ATTEND UNE DÉCISION HUMAINE. Une candidature oubliée
 *  est un vendeur qui part ailleurs ; un litige oublié est de l'argent
 *  gelé des deux côtés ; un écart de cantonnement non expliqué est le
 *  seul chiffre qui puisse mettre la plateforme en défaut.
 *
 *  D'où le parti pris : des compteurs d'en-cours et des anciennetés,
 *  pas un chiffre d'affaires. Le GMV flatte et ne demande rien à
 *  personne.
 * =====================================================================
 */
class TableauBordAdminService
{
    public function resume(): array
    {
        $candidaturesEnAttente = Candidature::whereIn('statut', ['en_attente', 'en_verification']);
        $litigesOuverts        = Litige::whereIn('statut', ['ouvert', 'en_examen']);

        return [
            'a_traiter' => [
                'candidatures'          => (int) (clone $candidaturesEnAttente)->count(),
                'litiges'               => (int) (clone $litigesOuverts)->count(),
                'produits_a_moderer'    => (int) Produit::where('statut_moderation', 'en_attente')->count(),
                'reversements_a_payer'  => (int) Reversement::whereIn('statut', ['a_payer', 'en_cours'])->count(),
                'reversements_echoues'  => (int) Reversement::where('statut', 'echoue')->count(),
            ],

            /*
             * L'ancienneté du plus vieux dossier en attente. C'est le
             * seul chiffre qui dit si l'exploitation suit ou décroche :
             * dix candidatures reçues hier ne posent pas de problème,
             * une seule qui attend depuis trois semaines si.
             */
            'plus_ancien_jours' => [
                'candidature' => $this->anciennete(
                    (clone $candidaturesEnAttente)->min('cree_le')
                ),
                'litige' => $this->anciennete(
                    (clone $litigesOuverts)->min('ouvert_le')
                ),
            ],

            'argent_cfa' => [
                /* Ce que la plateforme doit aux boutiques, tous pays
                 * confondus. C'est la dette, pas le chiffre d'affaires. */
                'du_aux_boutiques' => (int) SousCommande::whereIn('etat_fonds', ['sequestre', 'reverse'])
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))->from('reversement_lignes')
                          ->whereColumn('reversement_lignes.sous_commande_id', 'sous_commandes.id');
                    })
                    ->sum('montant_net_cfa'),

                'en_sequestre' => (int) SousCommande::where('etat_fonds', 'sequestre')
                    ->sum('montant_net_cfa'),

                'a_virer' => (int) Reversement::whereIn('statut', ['a_payer', 'en_cours'])
                    ->sum('montant_net_cfa'),
            ],

            /*
             * LE CHIFFRE LE PLUS IMPORTANT DE L'ÉCRAN.
             * Un écart entre le solde en banque et le solde du grand
             * livre signifie que l'argent des vendeurs et celui de la
             * plateforme se sont mélangés quelque part. Non expliqué,
             * c'est le seul indicateur qui puisse mettre Afrishop en
             * défaut face à un contrôle.
             */
            'cantonnement' => CantonnementJournalier::orderByDesc('date_arrete')
                ->first()
                ?->only(['date_arrete', 'pays_id', 'solde_banque_cfa',
                         'solde_grand_livre_cfa', 'ecart_cfa', 'statut', 'commentaire']),
        ];
    }

    private function anciennete(?string $date): ?int
    {
        return $date ? \Illuminate\Support\Carbon::parse($date)->diffInDays(now()) : null;
    }
}

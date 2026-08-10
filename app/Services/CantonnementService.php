<?php

namespace App\Services;

use App\Models\CantonnementJournalier;
use App\Models\Pays;
use Illuminate\Support\Carbon;

/**
 * =====================================================================
 *  CANTONNEMENT DES FONDS DE TIERS
 * =====================================================================
 *  L'instruction BCEAO n°001-01-2024 (art. 48) impose de conserver les
 *  fonds des clients sur un compte bancaire SÉPARÉ, au plus tard la fin
 *  du jour ouvrable suivant, avec rapprochement quotidien. Ces fonds
 *  sont soustraits aux créanciers de l'entreprise.
 *
 *  Cette classe produit l'arrêté quotidien : d'un côté le relevé
 *  bancaire, de l'autre la somme des dettes envers les boutiques telle
 *  que la connaît le grand livre. L'écart doit être NUL.
 *
 *  Un écart n'est pas une anomalie technique : c'est soit un
 *  encaissement non enregistré, soit un versement non comptabilisé,
 *  soit une erreur de saisie. Dans les trois cas, il faut le voir le
 *  jour même — pas au moment du contrôle, six mois plus tard.
 * =====================================================================
 */
class CantonnementService
{
    public function __construct(private GrandLivreService $grandLivre) {}

    /**
     * Arrête la journée pour un pays.
     *
     * @param  int  $soldeBanqueCfa  Relevé du compte de cantonnement,
     *                               saisi ou récupéré par API bancaire.
     */
    public function arreter(Pays $pays, Carbon $date, int $soldeBanqueCfa, ?int $valideParId = null): CantonnementJournalier
    {
        $soldeLivre = $this->grandLivre->totalDuAuxBoutiques($pays->id);
        $ecart = $soldeBanqueCfa - $soldeLivre;

        return CantonnementJournalier::updateOrCreate(
            ['date_arrete' => $date->toDateString(), 'pays_id' => $pays->id],
            [
                'solde_banque_cfa'      => $soldeBanqueCfa,
                'solde_grand_livre_cfa' => $soldeLivre,
                'ecart_cfa'             => $ecart,
                'statut'                => $ecart === 0 ? 'conforme' : 'ecart_a_expliquer',
                'valide_par_id'         => $valideParId,
            ]
        );
    }

    /**
     * Les arrêtés non conformes encore ouverts.
     * Destiné à une alerte quotidienne : un écart qui traîne plusieurs
     * jours est le signe d'un problème structurel, pas d'un incident.
     */
    public function ecartsOuverts(): \Illuminate\Support\Collection
    {
        return CantonnementJournalier::where('statut', 'ecart_a_expliquer')
            ->orderBy('date_arrete')->get();
    }
}

<?php

namespace App\Services;

use App\Models\SousCommande;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  SÉQUESTRE DES FONDS (version 2)
 * =====================================================================
 *  Ce qui change par rapport à la première conception :
 *
 *  1. Le séquestre n'est plus « chez le prestataire, sur le sous-compte
 *     de la boutique » — ce montage n'existe pas en UEMOA. Les fonds
 *     sont sur le compte de cantonnement de la plateforme, et le grand
 *     livre dit à qui ils appartiennent.
 *
 *  2. Un nouvel état d'entrée : « attente_encaissement ». Avec le
 *     paiement à la livraison, il n'y a rien à séquestrer tant que le
 *     livreur n'a pas encaissé.
 *
 *  Trois conditions cumulatives pour libérer les fonds :
 *    le colis est livré · aucun litige n'est ouvert · le client a
 *    confirmé, ou le délai de confirmation automatique est écoulé.
 *
 *  La troisième condition est celle qui évite qu'un vendeur attende
 *  indéfiniment parce qu'un client a simplement oublié de cliquer.
 * =====================================================================
 */
class SequestreService
{
    public function liberer(SousCommande $sc, string $origine = 'systeme'): void
    {
        if (! $this->liberables($sc)) {
            throw new RuntimeException(
                "Les fonds de {$sc->reference} ne sont pas libérables : " . $this->raisonBlocage($sc)
            );
        }

        DB::transaction(function () use ($sc, $origine) {
            $sc->update(['etat_fonds' => 'reverse']);
            $sc->journaliser('fonds_liberes',
                ['montant_net' => $sc->montant_net_cfa, 'origine' => $origine],
                auteurType: $origine === 'client' ? 'client' : 'systeme');
        });
    }

    public function liberables(SousCommande $sc): bool
    {
        if ($sc->statut !== 'livree')             return false;
        if ($sc->etat_fonds->value !== 'sequestre') return false;
        if ($sc->aUnLitigeOuvert())            return false;
        // Un retour en cours gèle aussi les fonds : rembourser après
        // avoir payé le vendeur oblige à lui reprendre l'argent, ce qui
        // est toujours une mauvaise conversation.
        if ($sc->aUnRetourEnCours())           return false;

        if ($sc->confirme_par_client_le !== null) return true;

        $delai = (int) parametre('delai_confirmation_auto_jours', 3);

        return $sc->livre_le !== null && $sc->livre_le->addDays($delai)->isPast();
    }

    public function rembourser(SousCommande $sc, string $motif, ?int $auteurId = null): void
    {
        if (! in_array($sc->etat_fonds->value, ['sequestre', 'attente_encaissement'], true)) {
            throw new RuntimeException(
                "Impossible de rembourser {$sc->reference} : les fonds sont déjà « {$sc->etat_fonds->value} »."
            );
        }

        DB::transaction(function () use ($sc, $motif, $auteurId) {
            $sc->update(['etat_fonds' => 'rembourse', 'statut' => 'annulee']);
            $sc->journaliser('rembourse', [
                'montant' => $sc->montant_articles_ttc_cfa + $sc->frais_livraison_cfa,
                'motif'   => $motif,
            ], $auteurId, 'admin');

            $sc->commande->rafraichirStatut();
        });
    }

    /** Balaie les livraisons non contestées dont le délai est écoulé. */
    public function libererLesEchues(): int
    {
        $delai = (int) parametre('delai_confirmation_auto_jours', 3);
        $n = 0;

        SousCommande::where('etat_fonds', 'sequestre')
            ->where('statut', 'livree')
            ->whereNull('confirme_par_client_le')
            ->where('livre_le', '<=', now()->subDays($delai))
            ->chunkById(200, function ($lot) use (&$n) {
                foreach ($lot as $sc) {
                    if (! $this->liberables($sc)) {
                        continue;
                    }
                    $this->liberer($sc, 'delai_ecoule');
                    $n++;
                }
            });

        return $n;
    }

    /** Explique en français pourquoi les fonds sont bloqués. */
    public function raisonBlocage(SousCommande $sc): string
    {
        return match (true) {
            $sc->etat_fonds->value === 'attente_encaissement' =>
                'la commande est payable à la livraison : rien n\'a encore été encaissé',
            $sc->etat_fonds->value === 'impaye' =>
                'le colis a été refusé à la porte : rien n\'a été encaissé',
            $sc->etat_fonds->value !== 'sequestre' =>
                "les fonds sont déjà « {$sc->etat_fonds->value} »",
            $sc->statut !== 'livree' =>
                "le colis n'est pas encore livré ({$sc->statut})",
            $sc->aUnLitigeOuvert() => 'un litige est en cours d\'examen',
            $sc->aUnRetourEnCours() => 'un retour est en cours de traitement',
            default =>
                'le client n\'a pas confirmé et le délai de '
                . parametre('delai_confirmation_auto_jours', 3) . ' jours n\'est pas écoulé',
        };
    }
}

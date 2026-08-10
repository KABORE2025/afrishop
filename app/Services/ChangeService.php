<?php

namespace App\Services;

use App\Models\TauxChange;
use RuntimeException;

/**
 * =====================================================================
 *  AFFICHAGE MULTI-DEVISE — SANS RISQUE DE CHANGE
 * =====================================================================
 *  Le franc CFA reste la MONNAIE DE COMPTE de la plateforme. Toutes
 *  les colonnes de montant sont en francs CFA, et le règlement se fait
 *  en francs CFA.
 *
 *  Cette classe ne sert qu'à AFFICHER un prix indicatif au visiteur
 *  étranger. Un prix en francs CFA sur une vitrine consultée depuis
 *  Paris ne veut rien dire pour l'acheteur ; un prix en euros lui parle
 *  immédiatement. Mais la plateforme n'encaisse pas d'euros et ne prend
 *  donc aucun risque de change.
 *
 *  DEUX PRÉCAUTIONS :
 *
 *   · Une MARGE de sécurité est appliquée sur les devises à taux
 *     flottant. Sans elle, un taux affiché le matin devient faux
 *     l'après-midi et le client s'estime trompé. L'euro n'en a pas
 *     besoin : sa parité avec le franc CFA est fixe.
 *
 *   · On refuse d'afficher une devise dont on n'a pas de taux à jour.
 *     Une conversion approximative est pire que pas de conversion : le
 *     client mémorise le chiffre et vous le rappellera.
 * =====================================================================
 */
class ChangeService
{
    /** Taux en vigueur : combien de francs CFA vaut une unité de cette devise. */
    public function taux(string $devise, ?\DateTimeInterface $date = null): TauxChange
    {
        $date = $date ?? now();

        $taux = TauxChange::where('devise', strtoupper($devise))
            ->where('date_debut', '<=', $date)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhere('date_fin', '>=', $date))
            ->orderByDesc('date_debut')
            ->first();

        if (! $taux) {
            throw new RuntimeException(
                "Aucun taux de change n'est disponible pour {$devise}. "
                . "Le prix ne sera affiché qu'en francs CFA."
            );
        }

        return $taux;
    }

    /**
     * Convertit un montant en francs CFA vers une devise d'affichage.
     * Arrondi à l'unité supérieure : afficher moins que le prix réel
     * expose à une réclamation, afficher un peu plus ne coûte rien.
     *
     * @return array{montant: float, devise: string, taux: float, indicatif: bool}
     */
    public function pourAffichage(int $montantCfa, string $devise): array
    {
        $devise = strtoupper($devise);

        if ($devise === 'XOF') {
            return ['montant' => $montantCfa, 'devise' => 'XOF', 'taux' => 1.0, 'indicatif' => false];
        }

        $t = $this->taux($devise);
        $avecMarge = (float) $t->xof_pour_une_unite * (1 - (float) $t->marge_pct / 100);
        $montant = ceil($montantCfa / $avecMarge * 100) / 100;

        return [
            'montant'   => $montant,
            'devise'    => $devise,
            'taux'      => (float) $t->xof_pour_une_unite,
            // Vrai dès que le taux flotte : la mention « prix indicatif,
            // règlement en francs CFA » doit alors apparaître à l'écran.
            'indicatif' => (float) $t->marge_pct > 0,
        ];
    }

    /** Devises réellement affichables aujourd'hui. */
    public function devisesDisponibles(): array
    {
        return TauxChange::whereNull('date_fin')->pluck('devise')->unique()->values()->all();
    }
}

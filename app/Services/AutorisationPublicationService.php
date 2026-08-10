<?php

namespace App\Services;

use App\Models\AutorisationPublication;
use App\Models\Produit;
use App\Models\TermeInterdit;
use App\Models\Utilisateur;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  AUTORISATION DE PUBLICATION — catégories réservées
 * =====================================================================
 *  CE QUI EST RÉSERVÉ, ET POURQUOI.
 *
 *  Les produits naturels — plantes, écorces, poudres, préparations
 *  traditionnelles — ne se publient pas librement. Non parce que le
 *  produit poserait problème : le baobab séché est un aliment banal.
 *  Mais parce que c'est LA RÉDACTION DE LA FICHE qui fait basculer.
 *
 *      « feuilles de baobab séchées »
 *          → un aliment, vendu librement
 *
 *      « feuilles de baobab qui soignent l'anémie »
 *          → une revendication thérapeutique, donc un médicament,
 *            donc une vente qui ne se fait pas hors officine
 *
 *  Le même sachet, deux régimes juridiques. Ce n'est pas le stock qu'on
 *  contrôle : c'est le texte.
 *
 *  LE SILENCE VAUT REFUS. Une fiche sans autorisation accordée reste
 *  invisible. C'est l'inverse du réflexe habituel — publier puis
 *  modérer — et c'est volontaire : sur une catégorie sensible, une
 *  fiche fautive vue pendant une heure a déjà produit son effet, et le
 *  retrait ne défait pas la lecture.
 *
 *  LE DÉTECTEUR NE DÉCIDE PAS. Il signale où regarder, à côté de la
 *  fiche, jamais à sa place. Un filtre automatique qui trancherait seul
 *  ferait les deux erreurs les plus chères à la fois : refuser des
 *  fiches honnêtes — « ne traite pas la peau » contient « traite » — et
 *  laisser passer les formulations habiles, qui n'emploient justement
 *  aucun des mots de la liste.
 * =====================================================================
 */
class AutorisationPublicationService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /** Vocabulaire interdit, en cache : la liste bouge rarement. */
    public function termesInterdits()
    {
        return Cache::remember('termes_interdits', 3600,
            fn () => TermeInterdit::all(['terme', 'gravite', 'explication']));
    }

    /**
     * Termes signalés dans un texte.
     *
     * On compare sur le texte NORMALISÉ, sinon « guérit » écrit
     * « guerit » passerait — et c'est précisément ainsi qu'on l'écrit
     * quand on veut passer.
     */
    public function analyser(string $texte): array
    {
        $recherche = app(RechercheService::class);
        $n = ' ' . $recherche->normaliser($texte) . ' ';
        $trouves = [];

        foreach ($this->termesInterdits() as $t) {
            $terme = $recherche->normaliser($t->terme);
            if ($terme !== '' && str_contains($n, ' ' . $terme . ' ')) {
                $trouves[] = [
                    'terme'       => $t->terme,
                    'gravite'     => $t->gravite,
                    'explication' => $t->explication,
                ];
            }
        }

        return $trouves;
    }

    /**
     * Un vendeur soumet sa fiche.
     *
     * Les termes « bloquants » sont refusés TOUT DE SUITE, à la
     * soumission, plutôt que trois jours plus tard par un relecteur.
     * Ce n'est pas de la sévérité, c'est de la politesse : le vendeur
     * corrige pendant qu'il a la fiche sous les yeux, au lieu d'attendre
     * un refus dont il ne se souviendra plus du contexte.
     */
    public function soumettre(Produit $produit): AutorisationPublication
    {
        if (!$produit->categorie->reservee) {
            throw new RuntimeException(
                "La catégorie « {$produit->categorie->libelle} » n'est pas réservée : "
                . "cette fiche se publie directement, sans passer par ici."
            );
        }

        $signales = $this->analyser($produit->nom . ' ' . $produit->description);
        $bloquants = array_filter($signales, fn ($s) => $s['gravite'] === 'bloquant');

        if ($bloquants) {
            $mots = implode(', ', array_column($bloquants, 'terme'));
            throw new RuntimeException(
                "Cette description ne peut pas être soumise en l'état : {$mots}. "
                . "Décrivez l'origine, la composition et la préparation du produit — "
                . "jamais ses effets sur la santé."
            );
        }

        return AutorisationPublication::updateOrCreate(
            ['produit_id' => $produit->id],
            [
                'statut'           => 'demande',
                'termes_signales'  => $signales,
                'demande_le'       => Carbon::now(),
                'decide_le'        => null,
                'motif'            => null,
            ]
        );
    }

    /** La fiche est-elle publiable ? Le silence vaut refus. */
    public function estPubliable(Produit $produit): bool
    {
        if (!$produit->categorie->reservee) {
            return true;
        }

        return AutorisationPublication::where('produit_id', $produit->id)
            ->where('statut', 'accorde')
            ->exists();
    }

    public function accorder(Produit $produit, Utilisateur $par, ?string $motif = null): void
    {
        DB::transaction(function () use ($produit, $par, $motif) {
            AutorisationPublication::where('produit_id', $produit->id)->update([
                'statut'                    => 'accorde',
                'motif'                     => $motif ?? 'Conforme — aucune allégation.',
                'decide_par_utilisateur_id' => $par->id,
                'decide_le'                 => Carbon::now(),
            ]);

            $this->notifications->fichePubliee($produit);
        });
    }

    /**
     * Refus. Le motif est OBLIGATOIRE et parcimonieusement contrôlé :
     * un refus sans explication se re-soumet à l'identique, et la file
     * se remplit des mêmes fiches indéfiniment.
     */
    public function refuser(Produit $produit, Utilisateur $par, string $motif): void
    {
        if (mb_strlen(trim($motif)) < 15) {
            throw new RuntimeException(
                "Un motif de refus doit expliquer ce qu'il faut corriger. Sans cela, "
                . "le vendeur re-soumettra la même fiche."
            );
        }

        DB::transaction(function () use ($produit, $par, $motif) {
            AutorisationPublication::where('produit_id', $produit->id)->update([
                'statut'                    => 'refuse',
                'motif'                     => $motif,
                'decide_par_utilisateur_id' => $par->id,
                'decide_le'                 => Carbon::now(),
            ]);

            $this->notifications->ficheRefusee($produit, $motif);
        });
    }

    /**
     * Révocation d'une fiche déjà publiée, après signalement.
     *
     * La fiche est dépubliée D'ABORD, la discussion vient ensuite. Sur
     * une allégation de santé, attendre la réponse du vendeur revient à
     * laisser le contenu en ligne le temps qu'il consulte ses messages —
     * et le préjudice, lui, ne s'interrompt pas pendant ce temps.
     */
    public function revoquer(Produit $produit, Utilisateur $par, string $motif): void
    {
        DB::transaction(function () use ($produit, $par, $motif) {
            AutorisationPublication::where('produit_id', $produit->id)->update([
                'statut'                    => 'revoque',
                'motif'                     => $motif,
                'decide_par_utilisateur_id' => $par->id,
                'decide_le'                 => Carbon::now(),
            ]);

            $this->notifications->ficheRevoquee($produit, $motif);
        });
    }

    public function file()
    {
        return AutorisationPublication::where('statut', 'demande')
            ->with('produit.boutique', 'produit.categorie')
            ->orderBy('demande_le')
            ->get();
    }
}

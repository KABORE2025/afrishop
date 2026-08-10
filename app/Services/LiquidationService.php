<?php

namespace App\Services;

use App\Models\Liquidation;
use App\Models\MotifLiquidation;
use App\Models\VarianteProduit;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  LIQUIDATION — écouler un lot sous son prix normal
 * =====================================================================
 *  Ce n'est pas une remise. C'est une remise QUI PORTE UN MOTIF, et le
 *  motif est montré au client. Trois différences avec un simple rabais :
 *
 *   1. LE MOTIF EST PUBLIC. Un prix barré sans explication passe pour
 *      une ficelle commerciale. « Lot dont la date approche » est une
 *      information honnête — et c'est cette honnêteté qui éteint le
 *      litige « on ne m'avait pas dit ». Un défaut annoncé et décrit ne
 *      fonde pas une réclamation ; c'est précisément pour cela qu'on
 *      l'annonce.
 *
 *   2. ELLE A UNE FIN. Sans date de fin, une liquidation devient le
 *      prix normal et le prix barré ne veut plus rien dire.
 *
 *   3. SI LE MOTIF EST LA PÉREMPTION, LA VENTE SE FERME TOUTE SEULE.
 *      C'est le point le plus important de cette classe. Un produit
 *      périmé qui reste achetable est le pire scénario du système :
 *      l'étiquette QR censée prouver l'origine devient la preuve de la
 *      faute, horodatée et signée par vous.
 *
 *  RÈGLE ABSOLUE POUR LE RESTE DU CODE : aucun calcul de prix ne lit
 *  `variantes.prix_cfa` directement. Tout passe par prixEffectif(). Un
 *  seul endroit qui l'oublie, et la commission, la retenue et la
 *  facture ne portent pas sur le même montant que ce que le client a
 *  payé — l'écart ne se voit qu'au rapprochement bancaire, des semaines
 *  plus tard.
 * =====================================================================
 */
class LiquidationService
{
    /**
     * La liquidation ACTIVE d'une variante, ou null.
     *
     * Trois conditions, dans cet ordre : elle existe, la période court,
     * et le lot n'est pas périmé. La troisième est la seule qui protège
     * vraiment — les deux premières ne sont que du calendrier.
     */
    public function active(VarianteProduit $variante, ?CarbonInterface $a = null): ?Liquidation
    {
        $a ??= Carbon::now();

        $liq = Liquidation::where('variante_id', $variante->id)
            ->where('debut_le', '<=', $a)
            ->where('fin_le', '>=', $a)
            ->orderByDesc('debut_le')
            ->first();

        if (!$liq) {
            return null;
        }

        // Périmé : la liquidation ne s'applique plus, et surtout le
        // produit n'est plus vendable du tout (voir estVendable).
        if ($liq->date_peremption !== null
            && Carbon::parse($liq->date_peremption)->endOfDay()->lt($a)) {
            return null;
        }

        return $liq;
    }

    /**
     * LE SEUL PRIX QUI DOIT SERVIR AUX CALCULS.
     *
     * Panier, commission, retenue à la source, facture, grand livre :
     * tous passent par ici. C'est ce qui garantit qu'ils portent tous
     * sur le même montant.
     */
    public function prixEffectif(VarianteProduit $variante, ?CarbonInterface $a = null): int
    {
        return $this->active($variante, $a)?->prix_liquide_cfa ?? $variante->prix_cfa;
    }

    /**
     * Vendable : il reste du stock ET le lot n'est pas périmé.
     *
     * On teste la péremption sur la liquidation MÊME EXPIRÉE, pas sur
     * la liquidation active. C'est subtil et c'est le cœur du garde-fou :
     * une fois la date passée, active() renvoie null, et se fier à lui
     * conclurait « pas de liquidation, donc produit normal, donc
     * vendable ». On remettrait en vente au prix fort un lot périmé.
     */
    public function estVendable(VarianteProduit $variante, ?CarbonInterface $a = null): bool
    {
        $a ??= Carbon::now();

        if ($variante->stock <= 0) {
            return false;
        }

        $peremption = Liquidation::where('variante_id', $variante->id)
            ->whereNotNull('date_peremption')
            ->orderByDesc('date_peremption')
            ->value('date_peremption');

        if ($peremption !== null && Carbon::parse($peremption)->endOfDay()->lt($a)) {
            return false;
        }

        return true;
    }

    /**
     * Met une variante en liquidation.
     *
     * Le prix de référence est RECOPIÉ, jamais lu à l'affichage. Si le
     * vendeur change son prix catalogue demain, le prix barré montré au
     * client ne doit pas bouger sous ses yeux — sinon la remise affichée
     * change toute seule, et un client attentif y verra une manipulation.
     */
    public function ouvrir(
        VarianteProduit $variante,
        string $codeMotif,
        int $prixLiquide,
        CarbonInterface $debut,
        CarbonInterface $fin,
        string $detail,
        ?CarbonInterface $datePeremption = null,
        ?int $quantite = null,
    ): Liquidation {
        $motif = MotifLiquidation::where('code', $codeMotif)->firstOrFail();

        if ($prixLiquide >= $variante->prix_cfa) {
            throw new RuntimeException(
                "Le prix de liquidation ({$prixLiquide}) doit être inférieur au prix normal "
                . "({$variante->prix_cfa}). Une « liquidation » au même prix n'est pas une "
                . "liquidation : c'est un mensonge affiché au client."
            );
        }

        if ($fin->lte($debut)) {
            throw new RuntimeException(
                "Une liquidation doit finir après avoir commencé. Sans fin, elle devient le "
                . "prix normal et le prix barré perd son sens."
            );
        }

        if ($motif->date_limite_obligatoire && $datePeremption === null) {
            throw new RuntimeException(
                "Le motif « {$motif->libelle} » exige une date limite. Sans elle, rien "
                . "n'empêchera la vente de continuer après péremption."
            );
        }

        if ($datePeremption !== null && $datePeremption->lt(Carbon::today())) {
            throw new RuntimeException(
                "La date limite est déjà passée : ce lot doit être retiré de la vente, "
                . "pas mis en promotion."
            );
        }

        // Une seule liquidation courante par variante. Deux liquidations
        // qui se chevauchent poseraient la question « lequel des deux
        // prix ? » — question à laquelle aucune réponse n'est bonne.
        return DB::transaction(function () use (
            $variante, $motif, $prixLiquide, $debut, $fin, $detail, $datePeremption, $quantite
        ) {
            Liquidation::where('variante_id', $variante->id)
                ->where('fin_le', '>=', $debut)
                ->where('debut_le', '<=', $fin)
                ->update(['fin_le' => $debut]);

            return Liquidation::create([
                'variante_id'        => $variante->id,
                'motif_id'           => $motif->id,
                'prix_liquide_cfa'   => $prixLiquide,
                'prix_reference_cfa' => $variante->prix_cfa,
                'debut_le'           => $debut,
                'fin_le'             => $fin,
                'date_peremption'    => $datePeremption?->toDateString(),
                'detail'             => $detail,
                'quantite_concernee' => $quantite,
            ]);
        });
    }

    /**
     * Remise affichée, en pourcentage entier.
     *
     * ATTENTION AU PIÈGE DE L'AFFICHAGE PRODUIT : la remise se calcule
     * sur la VARIANTE liquidée, jamais sur le produit. Un sac dont le
     * modèle moyen vaut 24 000 F et le grand 31 000 F, avec seulement le
     * grand liquidé à 24 800 F, afficherait « −0 % » si l'on comparait
     * le prix mini du produit à son prix normal mini. Un badge de
     * liquidation annonçant zéro pour cent est la seule chose que le
     * client ne croira pas.
     */
    public function remisePct(Liquidation $liq): int
    {
        return (int) round((1 - $liq->prix_liquide_cfa / $liq->prix_reference_cfa) * 100);
    }

    /**
     * Lots qui approchent leur date limite — la file de travail du
     * vendeur. Le seuil par défaut est volontairement large : trois
     * semaines laissent le temps d'écouler ; trois jours ne laissent que
     * le temps de jeter.
     */
    public function aSurveiller(int $joursAvant = 21)
    {
        return Liquidation::whereNotNull('date_peremption')
            ->whereBetween('date_peremption', [Carbon::today(), Carbon::today()->addDays($joursAvant)])
            ->with('variante.produit')
            ->orderBy('date_peremption')
            ->get();
    }

    /**
     * Retire de la vente tout lot dont la date est dépassée.
     *
     * Prévu pour une tâche planifiée quotidienne. Le contrôle existe
     * DÉJÀ au niveau de estVendable() : cette méthode ne le remplace
     * pas, elle rend l'état explicite en base pour qu'un inventaire, un
     * export ou un tableau de bord n'aient pas à rejouer la règle.
     * Deux verrous valent mieux qu'un quand le scénario raté consiste à
     * livrer un produit périmé.
     */
    public function fermerLesLotsPerimes(): int
    {
        $perimes = Liquidation::whereNotNull('date_peremption')
            ->whereDate('date_peremption', '<', Carbon::today())
            ->with('variante')
            ->get();

        $n = 0;
        foreach ($perimes as $liq) {
            if ($liq->variante && $liq->variante->stock > 0) {
                $liq->variante->update(['vendable' => false]);
                $n++;
            }
        }

        return $n;
    }
}

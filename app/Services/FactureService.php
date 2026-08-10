<?php

namespace App\Services;

use App\Models\Facture;
use App\Models\Pays;
use App\Models\SousCommande;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 *  FACTURATION
 * =====================================================================
 *  Deux factures distinctes sur une même vente, à ne jamais confondre :
 *
 *    · la BOUTIQUE facture le CLIENT — vente de marchandise ;
 *    · la PLATEFORME facture la BOUTIQUE — commission de service.
 *
 *  DIFFICULTÉ PROPRE À LA ZONE : au Bénin (e-MECeF), au Burkina (FEC),
 *  en Côte d'Ivoire (FNE) et au Niger (e-SECeF), une facture n'a valeur
 *  probante que si elle est certifiée par un dispositif de l'État,
 *  rattaché au numéro fiscal de l'ÉMETTEUR.
 *
 *  Conséquence directe : la plateforme ne peut pas auto-facturer
 *  librement pour un vendeur non enrôlé. Deux voies seulement :
 *    1. le vendeur émet lui-même depuis son propre dispositif ;
 *    2. un mandat de facturation, dont aucun texte consulté ne prévoit
 *       explicitement le cas pour les places de marché domestiques.
 *
 *  C'est un point à faire trancher par un fiscaliste local AVANT
 *  l'ouverture de chaque pays — pas après.
 * =====================================================================
 */
class FactureService
{
    public function __construct(private TaxeService $taxes) {}

    /**
     * Numéro de facture : séquence CONTINUE ET SANS TROU par pays et
     * par type. Les administrations fiscales vérifient précisément
     * cela : un numéro manquant est présumé être une facture dissimulée.
     *
     * Le verrouillage est indispensable : deux factures émises à la même
     * seconde ne doivent pas porter le même numéro.
     */
    public function prochainNumero(Pays $pays, string $type): string
    {
        return DB::transaction(function () use ($pays, $type) {
            $annee = now()->year;
            $prefixe = match ($type) {
                'vente_client'         => 'FV',
                'commission_boutique'  => 'FC',
                'avoir'                => 'AV',
            };

            $dernier = Facture::where('pays_id', $pays->id)->where('type', $type)
                ->where('numero', 'like', "{$prefixe}-{$annee}-%")
                ->lockForUpdate()->max('numero');

            $n = $dernier ? ((int) substr($dernier, -6)) + 1 : 1;

            return sprintf('%s-%d-%06d', $prefixe, $annee, $n);
        });
    }

    /** Facture de commission : la plateforme facture la boutique. */
    public function emettreCommission(SousCommande $sc): Facture
    {
        $boutique = $sc->boutique;
        $pays = $boutique->pays;

        // La plateforme, elle, est bien assujettie à la TVA sur ses
        // prestations de service, même quand la vente sous-jacente ne
        // l'est pas. Deux flux fiscaux, une seule transaction.
        $taux = $this->taxes->taux($pays, 'tva_normal');
        $v = $this->taxes->ventiler($sc->commission_cfa, $taux);

        return Facture::create([
            'type'                       => 'commission_boutique',
            'pays_id'                    => $pays->id,
            'numero'                     => $this->prochainNumero($pays, 'commission_boutique'),
            'emetteur_type'              => 'plateforme',
            'emetteur_nom'               => config('afrishop.raison_sociale', 'Afrishop'),
            'emetteur_numero_fiscal'     => config('afrishop.numero_fiscal'),
            'destinataire_nom'           => $boutique->nom,
            'destinataire_numero_fiscal' => $boutique->numero_fiscal,
            'destinataire_telephone'     => $boutique->telephone,
            'sous_commande_id'           => $sc->id,
            'montant_ht_cfa'             => $v['ht'],
            'montant_tva_cfa'            => $v['tva'],
            'taux_tva_pct'               => $taux,
            'montant_ttc_cfa'            => $v['ttc'],
            'certification_requise'      => (bool) $pays->facture_certifiee_obligatoire,
        ]);
    }

    /**
     * Transmet une facture au dispositif de certification de l'État.
     * Volontairement laissé à l'état de point d'extension : chaque pays
     * a sa propre interface (e-MECeF, FEC, FNE, e-SECeF), et les
     * intégrer sans convention signée avec l'administration n'aurait
     * aucun sens.
     */
    public function certifier(Facture $facture): void
    {
        if (! $facture->certification_requise) {
            return;
        }

        // À implémenter pays par pays, au moment de l'ouverture
        // commerciale. Tant que la facture n'est pas certifiée dans un
        // pays qui l'exige, elle n'a aucune valeur probante — et le
        // signaler est plus utile que de faire semblant.
        $facture->update([
            'certification_erreur' => 'Connecteur non encore implémenté pour ce pays.',
        ]);
    }
}

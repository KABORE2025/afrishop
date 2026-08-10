<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\DemandeExpedition;
use App\Models\Pays;
use App\Models\RemiseTransitaire;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  EXPÉDITION INTERNATIONALE — DÉLÉGUÉE, PAS GÉRÉE
 * =====================================================================
 *  DÉCISION STRUCTURANTE : la plateforme ne prend en charge NI le
 *  transport international, NI la douane.
 *
 *  Deux chemins seulement, tous deux à l'initiative du client :
 *
 *   1. REMISE À TRANSITAIRE. Le client désigne son propre transitaire.
 *      On prépare le colis, on le remet contre signature et pièce
 *      d'identité, et la responsabilité d'Afrishop s'arrête là.
 *
 *   2. DEVIS D'EXPÉDITION. Le client préfère qu'on s'en charge. On
 *      pèse, on mesure, on consulte un transporteur, on propose un
 *      prix FERME. Traitement manuel, et assumé comme tel.
 *
 *  CE QUE CE CHOIX ÉVITE — et c'est considérable :
 *   · les codes tarifaires douaniers par produit ;
 *   · le calcul des droits et taxes par pays de destination ;
 *   · les seuils de franchise, différents partout et changeants ;
 *   · le débat DDP / DAP et sa complexité contractuelle ;
 *   · les colis refusés en douane, première cause de perte sèche à
 *     l'international — transport aller-retour intercontinental payé
 *     pour rien.
 *
 *  Le prix à payer est une mention à écrire noir sur blanc et à faire
 *  accepter : LES DROITS ET TAXES À L'ARRIVÉE SONT À LA CHARGE DU
 *  DESTINATAIRE. Un client qui découvre 80 euros de douane sans avoir
 *  été prévenu refuse le colis et ne revient jamais.
 * =====================================================================
 */
class ExpeditionInternationaleService
{
    /**
     * Enregistre le transitaire désigné par le client.
     * On ne valide PAS ses coordonnées : c'est un tiers que le client
     * choisit et mandate, avec lequel Afrishop n'a aucun lien.
     */
    public function declarerTransitaire(Commande $commande, array $donnees): RemiseTransitaire
    {
        if ($commande->mode_livraison !== 'remise_transitaire') {
            throw new RuntimeException("Cette commande n'est pas en remise à transitaire.");
        }

        return RemiseTransitaire::create([
            'commande_id'           => $commande->id,
            'transitaire_nom'       => $donnees['transitaire_nom'],
            'transitaire_contact'   => $donnees['transitaire_contact'] ?? null,
            'transitaire_telephone' => $donnees['transitaire_telephone'] ?? null,
            'adresse_remise'        => $donnees['adresse_remise'],
            'reference_client'      => $donnees['reference_client'] ?? null,
            'instructions'          => $donnees['instructions'] ?? null,
            'statut'                => 'a_remettre',
            // La décharge est acceptée à la commande, et sa version est
            // conservée : les conditions changent, la commande d'hier
            // reste régie par celles d'hier.
            'decharge_version'      => parametre('decharge_transitaire_version', 'v1.0'),
            'decharge_acceptee_le'  => now(),
        ]);
    }

    /**
     * Remise effective du colis. C'est l'acte qui met fin à la
     * responsabilité de la plateforme — il exige donc une preuve.
     *
     * Sans nom, pièce d'identité et photo du bordereau, un colis perdu
     * en mer six semaines plus tard redevient notre problème.
     */
    public function remettre(RemiseTransitaire $remise, array $preuve, ?int $auteurId = null): void
    {
        foreach (['recu_par_nom', 'recu_par_piece'] as $obligatoire) {
            if (empty($preuve[$obligatoire])) {
                throw new RuntimeException(
                    "La remise exige le nom et la pièce d'identité de la personne qui réceptionne. "
                    . "Sans preuve, la responsabilité de la plateforme ne prend pas fin."
                );
            }
        }

        DB::transaction(function () use ($remise, $preuve, $auteurId) {
            $remise->update([
                'statut'          => 'remis',
                'remis_le'        => now(),
                'remis_par_id'    => $auteurId,
                'recu_par_nom'    => $preuve['recu_par_nom'],
                'recu_par_piece'  => $preuve['recu_par_piece'],
                'preuve_media_id' => $preuve['preuve_media_id'] ?? null,
                'nb_colis'        => $preuve['nb_colis'] ?? null,
                'poids_total_g'   => $preuve['poids_total_g'] ?? null,
            ]);

            foreach ($remise->commande->sousCommandes as $sc) {
                $sc->update(['statut' => 'livree', 'livre_le' => now()]);
                $sc->journaliser('remis_au_transitaire', [
                    'transitaire' => $remise->transitaire_nom,
                    'recu_par'    => $preuve['recu_par_nom'],
                ], $auteurId, 'agent');
            }

            $remise->commande->rafraichirStatut();
        });
    }

    /** Le client demande un devis d'expédition. */
    public function demanderDevis(Commande $commande, Pays $destination, array $donnees): DemandeExpedition
    {
        return DemandeExpedition::create([
            'commande_id'         => $commande->id,
            'reference'           => $this->prochaineReference(),
            'pays_destination_id' => $destination->id,
            'adresse_destination' => $donnees['adresse_destination'],
            'delai_souhaite'      => $donnees['delai_souhaite'] ?? 'standard',
            'commentaire_client'  => $donnees['commentaire_client'] ?? null,
            'statut'              => 'demande',
        ]);
    }

    /**
     * La plateforme propose un prix FERME.
     *
     * Ferme veut dire ferme : si le transport coûte finalement plus
     * cher, l'écart est pour nous. Un devis révisé après acceptation
     * détruit la confiance en une seule commande, et sur ce marché la
     * réputation circule vite.
     */
    public function proposerDevis(DemandeExpedition $demande, int $montantCfa,
                                  string $transporteur, int $delaiJours,
                                  ?int $poidsG = null, ?string $dimensions = null,
                                  ?int $auteurId = null): DemandeExpedition
    {
        $validite = (int) parametre('devis_expedition_validite_jours', 15);

        $demande->update([
            'poids_estime_g'      => $poidsG,
            'dimensions_cm'       => $dimensions,
            'transporteur_propose'=> $transporteur,
            'delai_estime_jours'  => $delaiJours,
            'montant_devis_cfa'   => $montantCfa,
            'valable_jusqu_au'    => now()->addDays($validite)->toDateString(),
            'statut'              => 'propose',
            'propose_le'          => now(),
            'traite_par_id'       => $auteurId,
            // Mention non négociable, répétée sur le devis.
            'droits_a_la_charge_du_client' => true,
        ]);

        return $demande->fresh();
    }

    public function accepterDevis(DemandeExpedition $demande): void
    {
        if ($demande->statut !== 'propose') {
            throw new RuntimeException("Ce devis n'est pas en attente de réponse.");
        }
        if ($demande->valable_jusqu_au < now()->toDateString()) {
            $demande->update(['statut' => 'expire']);
            throw new RuntimeException(
                "Ce devis a expiré le " . \Carbon\Carbon::parse($demande->valable_jusqu_au)->format('d/m/Y')
                . ". Les tarifs de transport bougent : demandez-en un nouveau."
            );
        }

        $demande->update(['statut' => 'accepte', 'repondu_le' => now()]);
    }

    protected function prochaineReference(): string
    {
        $annee = now()->year;
        $dernier = DemandeExpedition::where('reference', 'like', "EXP-$annee-%")->max('reference');
        $n = $dernier ? ((int) substr($dernier, -5)) + 1 : 1;

        return sprintf('EXP-%d-%05d', $annee, $n);
    }
}

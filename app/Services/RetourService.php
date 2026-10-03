<?php

namespace App\Services;

use App\Models\Retour;
use App\Models\SousCommande;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  RETOUR D'UN ARTICLE ET ANNULATION PAR LE CLIENT
 * =====================================================================
 *  RETOUR (pendant la fenêtre de 72 h après la remise) :
 *    1. le client demande  → statut « demande », les fonds sont GELÉS ;
 *    2. la boutique accepte ou refuse (motif obligatoire) ;
 *    3. acceptée : le client rapporte l'article ; la boutique confirme
 *       l'avoir RÉÇU → remboursement et remise en stock.
 *  Le client est remboursé à la réception, jamais à la demande : sinon
 *  il garderait l'article ET l'argent.
 *
 *  Le retour porte sur TOUT le colis. Les frais de renvoi sont à la
 *  charge du client, sauf erreur de la boutique (mauvais article).
 *
 *  ANNULATION : possible tant que la boutique n'a rien préparé
 *  (« à préparer »). Remboursement immédiat, stock rendu.
 * =====================================================================
 */
class RetourService
{
    public function __construct(
        private LitigeService $protection,
        private SequestreService $sequestre,
        private NotificationService $notifications,
    ) {}

    public function demander(SousCommande $sc, string $motif, ?string $commentaire): Retour
    {
        return DB::transaction(function () use ($sc, $motif, $commentaire) {
            $sc = SousCommande::with('boutique.utilisateur')->whereKey($sc->id)->lockForUpdate()->firstOrFail();

            if ($raison = $this->protection->raisonRefus($sc)) {
                throw new RuntimeException($raison);
            }
            // Une seule demande par colis : après un refus, le client qui
            // conteste passe par « Signaler un problème » ou le service client.
            if ($sc->retours()->exists()) {
                throw new RuntimeException('Un retour a déjà été demandé pour ce colis.');
            }

            $retour = Retour::create([
                'sous_commande_id'  => $sc->id,
                'reference'         => $this->prochaineReference(),
                'type'              => 'retractation',
                'motif'             => $motif,
                'commentaire'       => $commentaire,
                'statut'            => 'demande',
                'frais_a_la_charge' => $motif === 'erreur_commande' ? 'boutique' : 'client',
                'demande_le'        => now(),
            ]);

            $sc->journaliser('retour_demande', ['retour' => $retour->reference, 'motif' => $motif], auteurType: 'client');
            $this->prevenirBoutique($sc, "le client demande le retour du colis {$sc->reference} ({$retour->reference}). "
                .'Acceptez ou refusez dans votre espace vendeur.');

            return $retour;
        });
    }

    public function accepter(Retour $retour, int $auteurId): void
    {
        $this->changer($retour, 'demande', ['statut' => 'accepte', 'traite_le' => now()], 'retour_accepte', $auteurId);
    }

    public function refuser(Retour $retour, string $motif, int $auteurId): void
    {
        $this->changer($retour, 'demande', ['statut' => 'refuse', 'motif_refus' => $motif, 'traite_le' => now()],
            'retour_refuse', $auteurId, ['motif' => $motif]);
    }

    /** L'article est revenu : remboursement et stock rendu. */
    public function recevoir(Retour $retour, int $auteurId): void
    {
        DB::transaction(function () use ($retour, $auteurId) {
            $retour = Retour::whereKey($retour->id)->lockForUpdate()->firstOrFail();
            if ($retour->statut !== 'accepte') {
                throw new RuntimeException('Ce retour n’est pas en attente de réception.');
            }

            $sc = SousCommande::with('lignes.variante')->findOrFail($retour->sous_commande_id);
            $montant = (int) $sc->montant_articles_ttc_cfa + (int) $sc->frais_livraison_cfa;

            // Statut du retour mis à jour AVANT le remboursement : un retour
            // « en cours » gèle les fonds, et le remboursement les déplace.
            $retour->update(['statut' => 'rembourse', 'recu_le' => now(), 'montant_rembourse_cfa' => $montant]);

            $this->sequestre->rembourser($sc, "Retour {$retour->reference} reçu par la boutique", $auteurId, 'retournee', 'vendeur');

            foreach ($sc->lignes as $ligne) {
                $ligne->variante?->increment('stock', $ligne->quantite);
            }

            $sc->journaliser('retour_recu', ['retour' => $retour->reference], $auteurId, 'vendeur');
        });
    }

    public function annuler(SousCommande $sc): void
    {
        DB::transaction(function () use ($sc) {
            $sc = SousCommande::with(['lignes.variante', 'boutique.utilisateur'])->whereKey($sc->id)->lockForUpdate()->firstOrFail();

            if ($sc->statut !== 'a_preparer') {
                throw new RuntimeException('Ce colis est déjà en préparation ou parti : il ne peut plus être annulé. '
                    .'À réception, vous pourrez demander un retour.');
            }
            if ($sc->etat_fonds->value !== 'sequestre') {
                throw new RuntimeException('Ce colis ne peut pas être annulé ici : son paiement n’est pas confirmé.');
            }

            $this->sequestre->rembourser($sc, 'Annulation par le client avant préparation', null, 'annulee', 'client');

            foreach ($sc->lignes as $ligne) {
                $ligne->variante?->increment('stock', $ligne->quantite);
            }

            $this->prevenirBoutique($sc, "le client a annulé le colis {$sc->reference} : ne le préparez pas.");
        });
    }

    private function changer(Retour $retour, string $attendu, array $maj, string $evenement, int $auteurId, array $donnees = []): void
    {
        DB::transaction(function () use ($retour, $attendu, $maj, $evenement, $auteurId, $donnees) {
            $retour = Retour::whereKey($retour->id)->lockForUpdate()->firstOrFail();
            if ($retour->statut !== $attendu) {
                throw new RuntimeException('Ce retour a déjà été traité.');
            }
            $retour->update($maj);
            $retour->sousCommande->journaliser($evenement, ['retour' => $retour->reference, ...$donnees], $auteurId, 'vendeur');
        });
    }

    private function prevenirBoutique(SousCommande $sc, string $texte): void
    {
        $gerant = $sc->boutique?->utilisateur;
        if (! $gerant) {
            return;
        }

        \App\Models\Notification::create([
            'destinataire_id' => $gerant->id,
            'telephone'       => $gerant->telephone,
            'canal'           => 'sms',
            'corps_envoye'    => 'Afrishop : '.$texte,
            'statut'          => 'en_file',
            'nb_segments'     => 2,
        ]);
    }

    private function prochaineReference(): string
    {
        $annee = now()->year;
        $n = Retour::where('reference', 'like', "RET-{$annee}-%")->count() + 1;

        do {
            $reference = sprintf('RET-%d-%06d', $annee, $n++);
        } while (Retour::where('reference', $reference)->exists());

        return $reference;
    }
}

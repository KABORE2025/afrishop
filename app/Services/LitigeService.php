<?php

namespace App\Services;

use App\Models\Litige;
use App\Models\Media;
use App\Models\SousCommande;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * =====================================================================
 *  FENÊTRE DE PROTECTION ET LITIGES
 * =====================================================================
 *  Le code de remise prouve que le colis a été REMIS, pas que son
 *  contenu est conforme. Le client découvre un produit cassé ou faux en
 *  ouvrant le paquet, une fois le livreur reparti. C'est pourquoi les
 *  fonds restent en séquestre pendant `delai_confirmation_auto_jours`
 *  (72 h) après la remise, même quand le code a été validé :
 *
 *    · le client peut, pendant ce délai, confirmer que tout est
 *      conforme (les fonds partent tout de suite) ou signaler un
 *      problème (les fonds sont gelés jusqu'à l'arbitrage) ;
 *    · sans action de sa part, la tâche `afrishop:liberer-fonds`
 *      libère les fonds à l'expiration du délai.
 *
 *  Un litige s'ouvre ici et nulle part ailleurs — par le client lui-
 *  même, ou par un admin pour un client qui a appelé le support.
 * =====================================================================
 */
class LitigeService
{
    private MediaService $medias;

    public function __construct(
        private SequestreService $sequestre,
        private NotificationService $notifications,
    ) {
        $this->medias = new MediaService('local');
    }

    /** Fin de la fenêtre de protection, ou null si le colis n'est pas remis. */
    public function finProtection(SousCommande $sc): ?Carbon
    {
        return $sc->livre_le?->copy()->addDays((int) parametre('delai_confirmation_auto_jours', 3));
    }

    /**
     * Le client peut-il encore agir ? Colis remis, argent toujours en
     * séquestre, délai non écoulé, pas déjà confirmé, pas de litige ouvert.
     */
    public function peutAgir(SousCommande $sc): bool
    {
        return $this->raisonRefus($sc) === null;
    }

    public function raisonRefus(SousCommande $sc): ?string
    {
        $fin = $this->finProtection($sc);

        return match (true) {
            $sc->statut !== 'livree' || $fin === null
                => 'Le colis n’a pas encore été remis.',
            $sc->aUnLitigeOuvert()
                => 'Un signalement est déjà en cours d’examen pour ce colis.',
            // Un retour gèle déjà les fonds : confirmer maintenant
            // enregistrerait une réception que le retour contredit.
            $sc->aUnRetourEnCours()
                => 'Un retour est en cours pour ce colis : il sera traité par la boutique.',
            $sc->etat_fonds->value !== 'sequestre'
                => 'Ce colis est clos : la boutique a déjà été payée ou vous avez été remboursé.',
            $sc->confirme_par_client_le !== null
                => 'Vous avez déjà confirmé la bonne réception de ce colis.',
            $fin->isPast()
                => 'Le délai pour signaler un problème est écoulé.',
            default => null,
        };
    }

    /**
     * @param string         $auteurType 'client' ou 'admin' (ouverture pour le compte du client)
     * @param UploadedFile[]  $photos     Facultatives, Litige::PHOTOS_MAX au plus
     */
    public function ouvrir(SousCommande $sc, string $motif, string $description,
        string $auteurType = 'client', ?int $auteurId = null, array $photos = []): Litige
    {
        if (! in_array($motif, Litige::MOTIFS, true)) {
            throw new RuntimeException('Motif de litige inconnu.');
        }
        if (count($photos) > Litige::PHOTOS_MAX) {
            throw new RuntimeException('Deux photos au plus.');
        }

        return DB::transaction(function () use ($sc, $motif, $description, $auteurType, $auteurId, $photos) {
            // Verrou : deux clics ou deux onglets ne doivent pas ouvrir
            // deux litiges, ni en ouvrir un pendant la libération.
            $sc = SousCommande::with(['commande', 'boutique.utilisateur'])
                ->whereKey($sc->id)->lockForUpdate()->firstOrFail();

            // L'admin peut ouvrir après confirmation ou après le délai
            // (le client a appelé à temps, le support a traité tard) —
            // tant que l'argent n'est pas parti.
            if ($auteurType === 'admin') {
                if ($sc->aUnLitigeOuvert()) {
                    throw new RuntimeException('Un litige est déjà ouvert pour ce colis.');
                }
                if ($sc->etat_fonds->value !== 'sequestre') {
                    throw new RuntimeException('Les fonds ne sont plus en séquestre : il n’y a plus rien à geler.');
                }
            } elseif ($raison = $this->raisonRefus($sc)) {
                throw new RuntimeException($raison);
            }

            $litige = Litige::create([
                'sous_commande_id' => $sc->id,
                'reference'        => $this->prochaineReference(),
                'motif'            => $motif,
                'description'      => $description,
                'statut'           => 'ouvert',
                'ouvert_par_id'    => $auteurId,
                'ouvert_le'        => now(),
            ]);

            // Disque PRIVÉ : ces photos montrent souvent l'intérieur d'un
            // domicile. Elles ne sont servies que par lien signé et
            // temporaire (voir urlsPhotos()), jamais par /storage.
            foreach (array_values($photos) as $photo) {
                $this->medias->ajouterImage($photo, 'litige', $litige->id);
            }

            $sc->journaliser('litige_ouvert',
                ['litige' => $litige->reference, 'motif' => $motif, 'photos' => count($photos)],
                $auteurId, $auteurType);

            // La boutique doit savoir qu'elle a une version à donner :
            // sans elle, l'arbitrage se fait sur la seule parole du client.
            $gerant = $sc->boutique?->utilisateur;
            if ($gerant) {
                $this->notifications->envoyer('litige_ouvert', 'sms', [
                    'reference' => $sc->reference,
                    'litige'    => $litige->reference,
                ], destinataire: $gerant);
            }

            return $litige;
        });
    }

    /** « Tout est conforme » : le client lève lui-même la protection. */
    public function confirmerReception(SousCommande $sc): void
    {
        DB::transaction(function () use ($sc) {
            $sc = SousCommande::whereKey($sc->id)->lockForUpdate()->firstOrFail();

            if ($raison = $this->raisonRefus($sc)) {
                throw new RuntimeException($raison);
            }

            $sc->update(['confirme_par_client_le' => now()]);
            $sc->journaliser('reception_confirmee', [], auteurType: 'client');

            if ($this->sequestre->liberables($sc)) {
                $this->sequestre->liberer($sc, 'client');
            }
        });
    }

    /** La version de la boutique. Une seule réponse, non modifiable. */
    public function repondre(Litige $litige, string $argument, int $auteurId): void
    {
        DB::transaction(function () use ($litige, $argument, $auteurId) {
            $litige = Litige::whereKey($litige->id)->lockForUpdate()->firstOrFail();

            if (! $litige->estOuvert()) {
                throw new RuntimeException('Ce litige est déjà tranché.');
            }
            // Une réponse modifiable après coup ne prouverait plus rien :
            // l'admin doit pouvoir se fier à ce qu'il a lu.
            if ($litige->argument_boutique !== null) {
                throw new RuntimeException('La boutique a déjà répondu à ce litige.');
            }

            $litige->update([
                'argument_boutique'        => $argument,
                'conteste_par_boutique_le' => now(),
                'statut'                   => 'en_examen',
            ]);

            $litige->sousCommande->journaliser('litige_reponse_boutique',
                ['litige' => $litige->reference], $auteurId, 'vendeur');
        });
    }

    /**
     * Liens vers les photos, valables 30 minutes. Assez pour examiner un
     * litige, trop peu pour qu'un lien copié dans une conversation reste
     * une porte ouverte sur le domicile d'un client.
     */
    public function urlsPhotos(Litige $litige): array
    {
        $expire = now()->addMinutes(30);

        return $litige->photos->map(fn (Media $m) => [
            'vignette' => URL::temporarySignedRoute('litige.photo', $expire, ['media' => $m->id, 'taille' => 'vignette']),
            'grand'    => URL::temporarySignedRoute('litige.photo', $expire, ['media' => $m->id, 'taille' => 'grand']),
        ])->values()->all();
    }

    /**
     * SMS au client une fois le litige tranché. Sans lui, le client ne
     * découvre la décision qu'en retournant de lui-même sur sa page — et
     * appelle le support pour demander où en est son dossier.
     */
    public function prevenirClientDeLaDecision(Litige $litige): void
    {
        $sc = $litige->sousCommande()->with('commande')->first();
        if (! $sc) {
            return;
        }

        $this->notifications->envoyer('litige_tranche', 'sms', [
            'litige'    => $litige->reference,
            'reference' => $sc->commande->reference,
            'sens'      => $litige->statut === 'resolu_client' ? 'client' : 'boutique',
            'montant'   => number_format((int) $sc->montant_articles_ttc_cfa + (int) $sc->frais_livraison_cfa, 0, ',', ' '),
        ], telephone: $sc->commande->client_telephone);
    }

    private function prochaineReference(): string
    {
        $annee = now()->year;
        $n = Litige::where('reference', 'like', "LIT-{$annee}-%")->count() + 1;

        do {
            $reference = sprintf('LIT-%d-%06d', $annee, $n++);
        } while (Litige::where('reference', $reference)->exists());

        return $reference;
    }
}

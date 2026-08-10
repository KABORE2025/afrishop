<?php

namespace App\Services;

use App\Models\Devis;
use App\Models\Jalon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  JALONS — le paiement échelonné d'une prestation
 * =====================================================================
 *  LE PROBLÈME QUE CETTE CLASSE RÉSOUT.
 *
 *  Le séquestre marchand tient parce qu'il est court et petit : 35 000 F
 *  pendant trois jours. Appliqué tel quel à un chantier, il deviendrait
 *  25 millions pendant huit mois — et ce n'est plus la même activité.
 *  Détenir durablement des fonds de tiers de cet ordre relève d'un
 *  métier réglementé, avec cantonnement, fonds propres et agrément.
 *
 *  LA RÉPONSE : NE JAMAIS SÉQUESTRER LE CONTRAT ENTIER.
 *
 *  On séquestre le jalon en cours, et lui seul. Sur un chantier de
 *  25 millions découpé en quatre tranches, l'exposition de la plateforme
 *  plafonne à la plus grosse tranche — 7,5 millions — et n'y reste que
 *  le temps d'une étape, pas de tout le chantier.
 *
 *  MAIS DÉCOUPER NE SUFFIT PAS. 7,5 millions détenus trois semaines pour
 *  le compte d'un tiers, c'est encore l'activité d'un établissement de
 *  paiement. D'où un SECOND garde-fou, indépendant du premier : au-delà
 *  du plafond `services.plafond_sequestre_jalon_cfa`, la plateforme ne
 *  touche pas l'argent. Le client règle le prestataire directement ;
 *  Afrishop héberge le devis, les jalons et le procès-verbal de
 *  réception, et facture sa commission séparément.
 *
 *  On perd le levier du séquestre sur les gros chantiers. C'est un prix
 *  très inférieur à celui d'un exercice illégal d'activité réglementée.
 *  [JURISTE / BCEAO] : plafond à caler sur le régime d'agrément visé.
 *
 *  LES TROIS PARTIES Y GAGNENT, ce qui est rare :
 *
 *   · le client ne bloque que ce qu'il est sur le point de devoir, et
 *     garde un moyen de pression tant qu'il reste des tranches ;
 *   · l'artisan est payé au fil du chantier — c'est vital : il achète
 *     ses matériaux avec, et personne ne finance huit mois de travaux
 *     sur sa trésorerie ;
 *   · la plateforme réduit son exposition d'un ordre de grandeur.
 *
 *  ENCHAÎNEMENT D'UN JALON :
 *
 *      a_venir ─appeler()─→ en_cours ─encaisser()─→ (séquestre)
 *              ─demanderValidation()─→ a_valider
 *              ─valider()─→ valide (fonds reversés, jalon suivant appelé)
 *              ─contester()─→ conteste (fonds gelés, arbitrage)
 * =====================================================================
 */
class JalonService
{
    public function __construct(
        private SequestreService $sequestre,
        private GrandLivreService $grandLivre,
        private NotificationService $notifications,
    ) {
    }

    /**
     * Crée les jalons d'un devis accepté, à partir de la trame du service.
     *
     * LE CONTRÔLE QUI COMPTE : la somme des pourcentages doit faire
     * exactement 100. À 99, le prestataire n'est jamais payé en entier et
     * s'en aperçoit des mois plus tard ; à 101, c'est le client qui paie
     * plus que son devis. Les deux erreurs sont silencieuses au moment
     * où on les commet — d'où le refus à la création.
     *
     * L'écart d'arrondi va sur le DERNIER jalon : c'est celui de la
     * réception, le seul moment où le client vérifie le total. Le mettre
     * sur le premier ferait payer un acompte au montant bizarre, ce qui
     * inquiète toujours.
     */
    public function creerDepuisDevis(Devis $devis): array
    {
        $trame = $devis->demande->service->jalonsType()->orderBy('ordre')->get();

        if ($trame->isEmpty()) {
            throw new RuntimeException(
                "Le service « {$devis->demande->service->nom} » n'a aucune trame de jalons. "
                . "Sans découpage, il faudrait séquestrer le contrat entier."
            );
        }

        $somme = $trame->sum('pourcentage');
        if ($somme !== 100) {
            throw new RuntimeException(
                "Les jalons totalisent {$somme} % au lieu de 100 %. "
                . ($somme < 100
                    ? "Le prestataire ne serait jamais payé en entier."
                    : "Le client paierait plus que son devis.")
            );
        }

        return DB::transaction(function () use ($devis, $trame) {
            $jalons = [];
            $cumul = 0;
            $dernier = $trame->count() - 1;

            foreach ($trame as $i => $t) {
                $montant = $i === $dernier
                    ? $devis->montant_cfa - $cumul               // le reste exact
                    : (int) floor($devis->montant_cfa * $t->pourcentage / 100);
                $cumul += $montant;

                $jalons[] = Jalon::create([
                    'devis_id'     => $devis->id,
                    'ordre'        => $t->ordre,
                    'libelle'      => $t->libelle,
                    'pourcentage'  => $t->pourcentage,
                    'montant_cfa'  => $montant,
                    'statut'       => $i === 0 ? 'en_cours' : 'a_venir',
                    'etat_fonds'   => 'non_appele',
                ]);
            }

            return $jalons;
        });
    }

    /**
     * Appelle un jalon : le client est invité à régler cette tranche.
     *
     * On refuse d'appeler un jalon tant que le précédent n'est pas
     * validé. Sans cette règle, un prestataire pressé appellerait toutes
     * les tranches le premier jour, et le paiement par jalons
     * redeviendrait un paiement d'avance — exactement ce qu'on cherche à
     * éviter.
     */
    public function appeler(Jalon $jalon): Jalon
    {
        $precedent = Jalon::where('devis_id', $jalon->devis_id)
            ->where('ordre', '<', $jalon->ordre)
            ->orderByDesc('ordre')
            ->first();

        if ($precedent && $precedent->statut !== 'valide') {
            throw new RuntimeException(
                "Le jalon « {$precedent->libelle} » n'est pas validé. Appeler la tranche "
                . "suivante reviendrait à demander un paiement d'avance."
            );
        }

        $jalon->update([
            'statut'     => 'en_cours',
            'etat_fonds' => $this->passeParLaPlateforme($jalon)
                ? 'attente_encaissement'
                : 'hors_plateforme',
            'appele_le'  => Carbon::now(),
        ]);

        $this->notifications->jalonAppele($jalon);

        return $jalon;
    }

    /**
     * Ce jalon transite-t-il par la plateforme, ou est-il réglé en direct ?
     *
     * Le plafond est un PARAMÈTRE, pas une constante : il dépend du régime
     * d'agrément et changera. Le coder en dur obligerait à redéployer pour
     * une décision qui est juridique, pas technique.
     */
    public function passeParLaPlateforme(Jalon $jalon): bool
    {
        $plafond = (int) parametre('services.plafond_sequestre_jalon_cfa', 2_000_000);

        return $jalon->montant_cfa <= $plafond;
    }

    /**
     * Le client a payé la tranche : elle passe au séquestre.
     *
     * Un jalon réglé en direct N'A PAS D'ENCAISSEMENT à enregistrer ici —
     * l'argent n'est jamais passé par nous. Laisser la méthode l'accepter
     * silencieusement ferait apparaître au grand livre une somme que la
     * plateforme ne détient pas : le bilan mentirait.
     */
    public function encaisser(Jalon $jalon): Jalon
    {
        if ($jalon->etat_fonds === 'hors_plateforme') {
            throw new RuntimeException(
                "Ce jalon de " . number_format($jalon->montant_cfa, 0, ',', ' ') . " F dépasse le "
                . "plafond de séquestre : il se règle directement entre le client et le "
                . "prestataire. Afrishop n'encaisse pas cette somme."
            );
        }

        if ($jalon->etat_fonds !== 'attente_encaissement') {
            throw new RuntimeException(
                "Ce jalon n'attend pas d'encaissement (état : {$jalon->etat_fonds})."
            );
        }

        return DB::transaction(function () use ($jalon) {
            $jalon->update(['etat_fonds' => 'sequestre']);

            $this->grandLivre->ecrire(
                boutique: $jalon->devis->demande->boutique,
                type: 'vente_service',
                sens: 'credit',
                montant: $jalon->montant_cfa,
                piece: $jalon->devis->reference . '-J' . $jalon->ordre,
            );

            return $jalon;
        });
    }

    /**
     * Le client valide l'étape : les fonds sont libérés et la tranche
     * suivante est appelée automatiquement.
     *
     * L'enchaînement est automatique parce que le contraire crée des
     * chantiers à l'arrêt : le prestataire attend un appel de fonds que
     * personne ne déclenche, et il faut un coup de téléphone pour s'en
     * apercevoir. La machine sait quoi faire ensuite ; elle le fait.
     */
    public function valider(Jalon $jalon): Jalon
    {
        /* UN JALON RÉGLÉ EN DIRECT SE VALIDE QUAND MÊME. La validation
           n'est pas seulement un ordre de virement : c'est l'acte par
           lequel le client reconnaît l'étape faite. C'est lui qui fera
           foi en cas de litige, et il vaut aussi quand l'argent n'est
           jamais passé par la plateforme. Ce qui change, c'est qu'il n'y
           a rien à libérer ni à écrire au grand livre. */
        if ($jalon->etat_fonds === 'hors_plateforme') {
            return DB::transaction(function () use ($jalon) {
                $jalon->update(['statut' => 'valide', 'valide_le' => Carbon::now()]);

                /* La commission reste due. Faute de crédit en face, elle
                   creuse le solde de la boutique : c'est exactement ce
                   qu'on veut voir — une CRÉANCE d'Afrishop sur le
                   prestataire, à facturer séparément. Ne rien écrire du
                   tout ferait travailler la plateforme gratuitement sur
                   les plus gros contrats du catalogue. */
                $this->grandLivre->ecrire(
                    boutique: $jalon->devis->demande->boutique,
                    type: 'commission_a_facturer',
                    sens: 'debit',
                    montant: app(BaremeCommissionService::class)->commission($jalon->montant_cfa),
                    piece: $jalon->devis->reference . '-J' . $jalon->ordre,
                );

                $this->enchainer($jalon);

                return $jalon;
            });
        }

        if ($jalon->etat_fonds !== 'sequestre') {
            throw new RuntimeException(
                "Impossible de valider un jalon dont les fonds ne sont pas séquestrés "
                . "(état : {$jalon->etat_fonds}). Il n'y aurait rien à libérer."
            );
        }

        return DB::transaction(function () use ($jalon) {
            $boutique = $jalon->devis->demande->boutique;

            $jalon->update([
                'statut'     => 'valide',
                'etat_fonds' => 'reverse',
                'valide_le'  => Carbon::now(),
            ]);

            $bareme = app(BaremeCommissionService::class);
            $commission = $bareme->commission($jalon->montant_cfa);

            $this->grandLivre->ecrire(
                boutique: $boutique,
                type: 'commission',
                sens: 'debit',
                montant: $commission,
                piece: $jalon->devis->reference . '-J' . $jalon->ordre,
            );

            $this->enchainer($jalon);

            return $jalon;
        });
    }

    /**
     * Appelle la tranche suivante, ou prononce la fin du chantier.
     *
     * Extrait pour être appelé par les DEUX branches de valider() : que
     * la tranche soit passée par le séquestre ou réglée en direct, le
     * chantier doit avancer pareil. Dupliquer ces lignes reviendrait à
     * laisser un jour l'une des deux branches diverger — et un chantier
     * réglé en direct s'arrêterait sans que personne comprenne pourquoi.
     */
    private function enchainer(Jalon $jalon): void
    {
        $suivant = Jalon::where('devis_id', $jalon->devis_id)
            ->where('ordre', '>', $jalon->ordre)
            ->orderBy('ordre')
            ->first();

        if ($suivant) {
            $this->appeler($suivant);
        } else {
            $this->notifications->chantierTermine($jalon->devis);
        }
    }

    /**
     * Le client conteste l'étape : les fonds restent bloqués.
     *
     * On ne rembourse PAS automatiquement. Sur un chantier, la vérité
     * est rarement d'un seul côté : une dalle mal finie n'annule pas
     * les fondations. L'arbitrage tranche, et il peut trancher pour un
     * versement partiel — ce qu'un remboursement automatique aurait
     * rendu impossible.
     */
    public function contester(Jalon $jalon, string $motif): Jalon
    {
        $jalon->update(['statut' => 'conteste']);
        $this->notifications->jalonConteste($jalon, $motif);

        return $jalon;
    }

    /**
     * EXPOSITION DE LA PLATEFORME sur un devis : ce qu'elle détient
     * réellement à cet instant.
     *
     * Ce chiffre est la justification du découpage en jalons, et il
     * mérite d'être affiché en console : c'est lui, pas le montant des
     * contrats, qui mesure le risque porté.
     */
    public function expositionCourante(Devis $devis): int
    {
        return (int) Jalon::where('devis_id', $devis->id)
            ->where('etat_fonds', 'sequestre')
            ->sum('montant_cfa');
    }
}

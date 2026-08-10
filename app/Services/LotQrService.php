<?php

namespace App\Services;

use App\Enums\StatutLotQr;
use App\Models\CodeQr;
use App\Models\LotQr;
use App\Models\Produit;
use App\Models\ScanQr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * =====================================================================
 *  TRAÇABILITÉ QR — génération des lots et vérification des scans
 * =====================================================================
 *
 *  LE PROBLÈME
 *  Un client achète un pot de beurre de karité sur un étal. Rien ne lui
 *  dit s'il vient réellement d'une boutique Afrishop, ni s'il est encore
 *  consommable. La contrefaçon de cosmétiques et de produits
 *  alimentaires est courante, et une intoxication attribuée à tort à une
 *  boutique honnête détruirait sa réputation.
 *
 *  LA RÉPONSE
 *  Chaque unité produite reçoit une étiquette unique. Le client scanne
 *  et voit immédiatement : le produit, la boutique, les dates, et un
 *  verdict clair — authentique, périmé, ou suspect.
 *
 *  LA SUBTILITÉ QUI FAIT TOUT
 *  L'étiquette porte DEUX identifiants de nature opposée :
 *
 *    « 02/08/2026/0026 » est imprimé en clair. Il est séquentiel donc
 *    prévisible, et c'est voulu : un humain doit pouvoir le lire, le
 *    noter, le dicter au téléphone. Il IDENTIFIE.
 *
 *    « K7M2P9QRXW4T » est caché dans le QR code. Il est tiré au hasard
 *    et impossible à deviner. Il AUTHENTIFIE.
 *
 *  Si le QR contenait le numéro séquentiel, n'importe qui voyant une
 *  étiquette 0026 pourrait fabriquer une 0027 valide et imprimer autant
 *  de fausses étiquettes qu'il veut, toutes reconnues authentiques par
 *  votre propre site. C'est l'erreur classique de ce type de dispositif.
 * =====================================================================
 */
class LotQrService
{
    /**
     * Crée un lot et génère toutes ses étiquettes.
     *
     * @param  array{produit_id:int, date_fabrication:string, date_expiration:string,
     *               fabricant:string, quantite:int, numero_debut?:int,
     *               description?:string, largeur_numero?:int}  $donnees
     * @param  int  $creeParId  L'administrateur qui génère le lot
     * @param  int|null  $demandeParId  Le vendeur à l'origine de la demande
     */
    public function creerLot(array $donnees, int $creeParId, ?int $demandeParId = null): LotQr
    {
        $produit = Produit::findOrFail($donnees['produit_id']);

        // Garde-fou métier : on ne colle pas de date de péremption sur
        // un panier en osier. Le drapeau « tracable » est posé à la
        // création du produit et doit être explicite.
        if (! $produit->tracable) {
            throw new RuntimeException(
                "Le produit « {$produit->nom} » n'est pas marqué comme traçable. " .
                "Activez la traçabilité sur la fiche produit avant de générer des étiquettes."
            );
        }

        $quantite = (int) $donnees['quantite'];
        $max = config('afrishop.qr.quantite_max_par_lot', 10000);

        // Un zéro de trop dans le formulaire créerait des millions de
        // lignes et bloquerait la base plusieurs minutes.
        if ($quantite < 1 || $quantite > $max) {
            throw new RuntimeException("La quantité doit être comprise entre 1 et {$max}.");
        }

        $fabrication = Carbon::parse($donnees['date_fabrication']);
        $expiration  = Carbon::parse($donnees['date_expiration']);

        // Erreur de saisie fréquente : les deux dates inversées. Un lot
        // entier d'étiquettes serait inexploitable.
        if ($expiration->lessThanOrEqualTo($fabrication)) {
            throw new RuntimeException("La date d'expiration doit être postérieure à la date de fabrication.");
        }

        // Si le numéro de départ n'est pas fourni, on reprend la
        // numérotation là où le lot précédent s'est arrêté.
        $numeroDebut = $donnees['numero_debut'] ?? LotQr::prochainNumeroDebut($produit->id);

        return DB::transaction(function () use ($donnees, $produit, $quantite, $fabrication, $expiration, $numeroDebut, $creeParId, $demandeParId) {

            $lot = LotQr::create([
                'produit_id'       => $produit->id,
                'boutique_id'      => $produit->boutique_id,
                'reference'        => LotQr::prochaineReference(),
                'date_fabrication' => $fabrication,
                'date_expiration'  => $expiration,
                'fabricant'        => $donnees['fabricant'],
                'numero_debut'     => $numeroDebut,
                'quantite'         => $quantite,
                'largeur_numero'   => $donnees['largeur_numero'] ?? config('afrishop.qr.largeur_numero_defaut', 4),
                'description'      => $donnees['description'] ?? null,
                'statut'           => StatutLotQr::Genere,
                'cree_par_id'      => $creeParId,
                'demande_par_id'   => $demandeParId,
            ]);

            $this->genererCodes($lot);

            return $lot->fresh('codes');
        });
    }

    /**
     * Génère les étiquettes du lot.
     *
     * Insertion par paquets de 500 : sur un lot de 10 000 étiquettes,
     * 10 000 INSERT individuels prendraient plusieurs minutes et
     * saturerait la connexion. Un insert groupé prend quelques secondes.
     */
    protected function genererCodes(LotQr $lot): void
    {
        $dateCode = $lot->date_fabrication->format('d/m/Y');
        $lignes = [];
        $maintenant = now();

        for ($i = 0; $i < $lot->quantite; $i++) {
            $numero = $lot->numero_debut + $i;

            $lignes[] = [
                'lot_qr_id'    => $lot->id,
                'numero'       => $numero,

                // Format demandé : 02/08/2026/0026
                'code_lisible' => $dateCode . '/' . str_pad((string) $numero, $lot->largeur_numero, '0', STR_PAD_LEFT),

                'jeton'        => $this->genererJetonUnique(),
                'statut'       => 'genere',
                'nb_scans'     => 0,
                'cree_le'      => $maintenant,
            ];

            if (count($lignes) === 500) {
                CodeQr::insert($lignes);
                $lignes = [];
            }
        }

        if ($lignes !== []) {
            CodeQr::insert($lignes);
        }
    }

    /**
     * Tire un jeton aléatoire non encore utilisé.
     *
     * L'alphabet exclut les caractères ambigus (0/O, 1/I/L) pour qu'un
     * client puisse recopier le jeton à la main si son téléphone ne lit
     * pas le QR.
     *
     * random_int() est un générateur cryptographique : contrairement à
     * rand() ou mt_rand(), sa sortie n'est pas prédictible même en
     * observant des milliers de jetons précédents. Ce détail est
     * essentiel — un générateur faible rendrait tout le dispositif
     * contournable.
     */
    protected function genererJetonUnique(): string
    {
        $alphabet = config('afrishop.qr.alphabet_jeton');
        $longueur = config('afrishop.qr.longueur_jeton', 12);
        $dernier  = strlen($alphabet) - 1;

        // La collision est quasi impossible (~10^17 combinaisons) mais
        // la contrainte UNIQUE en base ferait échouer tout le lot : on
        // vérifie donc, avec un nombre d'essais borné.
        for ($essai = 0; $essai < 5; $essai++) {
            $jeton = '';
            for ($i = 0; $i < $longueur; $i++) {
                $jeton .= $alphabet[random_int(0, $dernier)];
            }

            if (! CodeQr::where('jeton', $jeton)->exists()) {
                return $jeton;
            }
        }

        throw new RuntimeException('Impossible de générer un jeton unique après 5 essais.');
    }

    // -----------------------------------------------------------------
    //  VÉRIFICATION — appelée à chaque scan client
    // -----------------------------------------------------------------

    /**
     * Vérifie un jeton scanné et enregistre le passage.
     *
     * Renvoie toujours un tableau exploitable, même en cas d'échec :
     * la page publique doit savoir afficher « code inconnu » aussi
     * proprement qu'un produit authentique.
     *
     * @return array{verdict:string, message:string, code?:CodeQr, lot?:LotQr}
     */
    public function verifier(string $jeton, ?string $ip = null, ?string $agent = null, ?string $ville = null): array
    {
        $code = CodeQr::with(['lot.produit.boutique'])->where('jeton', $jeton)->first();

        /*
         * Jeton inconnu = contrefaçon quasi certaine.
         *
         * On répond de façon identique et sans détail supplémentaire, et
         * surtout SANS indiquer si le jeton « ressemble » à un jeton
         * valide : toute nuance dans la réponse donnerait à un
         * contrefacteur un moyen de tester ses tentatives.
         */
        if (! $code) {
            return [
                'verdict' => 'inconnu',
                'message' => "Ce code ne correspond à aucun produit Afrishop. "
                           . "Il peut s'agir d'une contrefaçon — ne consommez pas ce produit et signalez-le nous.",
            ];
        }

        $lot = $code->lot;

        // On enregistre le scan AVANT de calculer le verdict : même un
        // produit rappelé ou périmé doit être compté, ce sont justement
        // les scans les plus intéressants à suivre.
        $this->enregistrerScan($code, $ip, $agent, $ville);

        $verdict = match (true) {
            $lot->statut === StatutLotQr::Rappele || $code->statut === 'rappele' => 'rappele',
            $code->statut === 'desactive'                                        => 'desactive',
            $lot->estPerime()                                                    => 'perime',
            $code->estSuspect()                                                  => 'suspect',
            default                                                              => 'authentique',
        };

        $message = match ($verdict) {
            'rappele'     => "RAPPEL PRODUIT — ce lot fait l'objet d'un rappel. Ne consommez pas ce produit et rapprochez-vous de la boutique.",
            'desactive'   => "Cette étiquette a été désactivée par Afrishop. Contactez-nous avant de consommer le produit.",
            'perime'      => "Produit authentique, mais la date limite est dépassée depuis le "
                             . $lot->date_expiration->format('d/m/Y') . ". Ne le consommez pas.",
            'suspect'     => "Produit authentique, mais cette étiquette a été scannée un nombre inhabituel de fois. "
                             . "Elle a peut-être été copiée. Vérifiez auprès de la boutique.",
            'authentique' => "Produit authentique, vendu par " . $lot->produit->boutique->nom . ".",
        };

        return ['verdict' => $verdict, 'message' => $message, 'code' => $code->fresh(), 'lot' => $lot];
    }

    /**
     * Journalise un scan et met à jour les compteurs.
     *
     * increment() est utilisé plutôt qu'une lecture suivie d'une
     * écriture : si deux personnes scannent la même étiquette à la même
     * seconde, un « lire puis écrire » perdrait un des deux scans.
     */
    protected function enregistrerScan(CodeQr $code, ?string $ip, ?string $agent, ?string $ville): void
    {
        DB::transaction(function () use ($code, $ip, $agent, $ville) {

            ScanQr::create([
                'code_qr_id'    => $code->id,
                'scanne_le'     => now(),
                'ip_hachee'     => ScanQr::hacher($ip),
                'agent_hache'   => ScanQr::hacher($agent),
                'ville_estimee' => $ville,
            ]);

            $code->increment('nb_scans');

            $maj = ['dernier_scan_le' => now()];

            // Le premier scan fait basculer l'étiquette en « active » :
            // le produit est sorti du stock et se trouve chez un client.
            if ($code->premier_scan_le === null) {
                $maj['premier_scan_le'] = now();
                if ($code->statut === 'imprime' || $code->statut === 'genere') {
                    $maj['statut'] = 'active';
                }
            }

            $code->update($maj);
        });
    }

    /**
     * Déclenche un rappel produit sur un lot entier.
     *
     * Effet immédiat : toutes les étiquettes déjà en circulation
     * affichent un avertissement rouge au prochain scan. C'est le seul
     * moyen de joindre des clients dont on ne connaît pas l'identité.
     */
    public function rappelerLot(LotQr $lot, string $motif): void
    {
        DB::transaction(function () use ($lot, $motif) {
            $lot->update(['statut' => StatutLotQr::Rappele, 'description' => $motif]);
            $lot->codes()->update(['statut' => 'rappele']);
        });
    }
}

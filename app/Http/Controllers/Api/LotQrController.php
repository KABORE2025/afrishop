<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatutLotQr;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreerLotQrRequest;
use App\Models\LotQr;
use App\Services\LotQrService;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Console d'administration de la traçabilité.
 * Réservée aux administrateurs Afrishop (middleware « admin » sur les routes).
 */
class LotQrController extends Controller
{
    public function __construct(private LotQrService $lots) {}

    /**
     * POST /api/admin/lots-qr
     *
     * Exemple de charge utile :
     * {
     *   "produit_id": 1,
     *   "date_fabrication": "2026-08-02",
     *   "date_expiration": "2028-08-02",
     *   "fabricant": "Karité du Sahel — Ouagadougou",
     *   "numero_debut": 26,
     *   "quantite": 275,
     *   "description": "Beurre de karité brut 500 g. À conserver à l'abri de la chaleur."
     * }
     *
     * Produit les étiquettes 02/08/2026/0026 à 02/08/2026/0300.
     */
    // -----------------------------------------------------------------
    //  CONSOLE
    // -----------------------------------------------------------------

    /** Tous les lots, demandes des boutiques en tête. */
    public function index(Request $r): JsonResponse
    {
        $page = LotQr::with(['produit:id,nom', 'boutique:id,nom'])
            ->when($r->input('statut'), fn ($q, $st) => $q->where('statut', $st))
            ->orderByRaw("statut = 'demande' desc")
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (LotQr $l) => $this->resume($l)),
            'meta' => ['page' => $page->currentPage(), 'dernier' => $page->lastPage(), 'total' => $page->total()],
            'produits_tracables' => Produit::where('tracable', true)->with('boutique:id,nom')
                ->orderBy('nom')->get(['id', 'nom', 'boutique_id'])
                ->map(fn ($p) => ['id' => $p->id, 'nom' => $p->nom, 'boutique' => $p->boutique?->nom]),
        ]);
    }

    public function creer(CreerLotQrRequest $request)
    {
        try {
            $lot = $this->lots->creerLot(
                donnees: $request->validated(),
                creeParId: $request->user()->id,
                demandeParId: $request->input('demande_par_id'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'reference'    => $lot->reference,
            'quantite'     => $lot->quantite,
            'premier_code' => $lot->codes()->orderBy('numero')->first()->code_lisible,
            'dernier_code' => $lot->codes()->orderByDesc('numero')->first()->code_lisible,
            'url_planche'  => $this->urlPlanche($lot),
        ], 201);
    }

    /** Accepter la demande d'une boutique : les étiquettes sont générées. */
    public function generer(Request $r, LotQr $lot): JsonResponse
    {
        abort_unless($lot->statut === StatutLotQr::Demande, 422, 'Ce lot n’est pas une demande en attente.');

        $donnees = $r->validate([
            'numero_debut'   => ['nullable', 'integer', 'min:0'],
            'largeur_numero' => ['nullable', 'integer', 'min:3', 'max:8'],
        ]);

        try {
            $lot = $this->lots->creerLot(array_merge([
                'produit_id'       => $lot->produit_id,
                'date_fabrication' => $lot->date_fabrication->toDateString(),
                'date_expiration'  => $lot->date_expiration->toDateString(),
                'fabricant'        => $lot->fabricant,
                'quantite'         => $lot->quantite,
                'description'      => $lot->description,
            ], array_filter($donnees, fn ($v) => $v !== null)), $r->user()->id, $lot->demande_par_id, $lot);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Lot {$lot->reference} généré : {$lot->quantite} étiquettes.",
            'lot' => $this->resume($lot), 'url_planche' => $this->urlPlanche($lot)]);
    }

    public function refuser(Request $r, LotQr $lot): JsonResponse
    {
        abort_unless($lot->statut === StatutLotQr::Demande, 422, 'Ce lot n’est pas une demande en attente.');
        $donnees = $r->validate(['motif' => ['required', 'string', 'min:10', 'max:1000']]);

        $lot->update(['statut' => StatutLotQr::Refuse, 'motif_refus' => $donnees['motif']]);

        return response()->json(['message' => 'Demande refusée.']);
    }

    /**
     * Lien d'impression de la planche. Lien SIGNÉ et temporaire (30 min) :
     * la planche s'ouvre dans un onglet, où le navigateur n'envoie pas le
     * jeton de l'API. Sans signature, n'importe qui connaissant l'adresse
     * imprimerait de vraies étiquettes.
     */
    public function lienPlanche(LotQr $lot): JsonResponse
    {
        abort_if(in_array($lot->statut, [StatutLotQr::Demande, StatutLotQr::Refuse], true), 422,
            'Ce lot n’a pas d’étiquettes.');

        return response()->json(['url' => $this->urlPlanche($lot)]);
    }

    /**
     * GET /api/admin/lots-qr/{lot}/planche
     *
     * Génère la planche d'étiquettes à imprimer : pour chaque code, le
     * QR (qui contient le jeton) et, sous l'image, le code lisible et
     * les dates.
     *
     * Le niveau de correction d'erreur est réglé sur HIGH : une
     * étiquette collée sur un pot va être manipulée, mouillée, frottée.
     * HIGH permet au QR de rester lisible même avec 30 % de la surface
     * endommagée, au prix d'un motif un peu plus dense.
     */
    public function planche(LotQr $lot)
    {
        $lot->load('produit.boutique');

        $etiquettes = $lot->codes()->orderBy('numero')->get()->map(function ($code) {
            // endroid/qr-code 6 : arguments nommés au constructeur. L'ancien
            // `Builder::create()->data()…` n'existe plus — la planche plantait.
            $qr = (new Builder(
                data: $code->urlVerification(),
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: 220,
                margin: 6,
            ))->build();

            return [
                'code_lisible' => $code->code_lisible,
                // Image intégrée en base64 : la planche reste un seul
                // fichier HTML, imprimable hors ligne, sans dépendre
                // d'un serveur d'images.
                'qr_data_uri'  => $qr->getDataUri(),
            ];
        });

        // Le téléchargement de la planche fait foi : les étiquettes
        // existent désormais physiquement.
        if ($lot->statut === StatutLotQr::Genere) {
            $lot->update(['statut' => StatutLotQr::Imprime]);
            $lot->codes()->where('statut', 'genere')->update(['statut' => 'imprime']);
        }

        return view('planche-qr', compact('lot', 'etiquettes'));
    }

    /**
     * POST /api/admin/lots-qr/{lot}/rappel
     *
     * Rappel produit. Toutes les étiquettes du lot déjà en circulation
     * afficheront un avertissement rouge au prochain scan. C'est le seul
     * moyen de joindre des clients dont on ne connaît pas l'identité.
     */
    public function rappeler(Request $request, LotQr $lot)
    {
        $request->validate(['motif' => ['required', 'string', 'min:10']]);
        abort_if(in_array($lot->statut, [StatutLotQr::Demande, StatutLotQr::Refuse, StatutLotQr::Rappele], true), 422,
            'Ce lot ne peut pas être rappelé.');

        $this->lots->rappelerLot($lot, $request->input('motif'));

        return response()->json([
            'message'          => "Rappel enregistré. {$lot->quantite} étiquettes basculées en alerte.",
            'codes_concernes'  => $lot->quantite,
            'deja_en_circulation' => $lot->codes()->where('nb_scans', '>', 0)->count(),
        ]);
    }

    /**
     * GET /api/admin/lots-qr/{lot}/statistiques
     * Suivi de terrain : combien d'étiquettes ont été scannées, où, et
     * lesquelles présentent un profil anormal.
     */
    public function statistiques(LotQr $lot)
    {
        return [
            'reference'      => $lot->reference,
            'quantite'       => $lot->quantite,
            'jamais_scannes' => $lot->codes()->where('nb_scans', 0)->count(),
            'en_circulation' => $lot->codes()->where('nb_scans', '>', 0)->count(),
            'suspects'       => $lot->codes()
                ->where('nb_scans', '>=', config('afrishop.qr.seuil_scans_suspects'))
                ->get(['code_lisible', 'nb_scans']),
            'expire_dans'    => $lot->joursAvantExpiration() . ' jours',
        ];
    }

    // -----------------------------------------------------------------
    //  ESPACE VENDEUR — demander, jamais générer
    // -----------------------------------------------------------------

    /**
     * Le vendeur DEMANDE un lot ; c'est Afrishop qui le génère. Lui
     * laisser générer ses propres codes reviendrait à lui laisser
     * fabriquer ses propres preuves d'authenticité.
     *
     * Cette route existait, mais la méthode qu'elle appelait
     * (VendeurController::demanderLotQr) n'avait jamais été écrite.
     */
    public function demander(Request $r): JsonResponse
    {
        $boutique = $r->user()?->boutique;
        abort_unless($boutique, 403, 'Aucune boutique rattachée à ce compte.');

        $donnees = $r->validate([
            'produit_id'       => ['required', 'integer', Rule::exists('produits', 'id')->where('boutique_id', $boutique->id)],
            'date_fabrication' => ['required', 'date', 'before_or_equal:today'],
            'date_expiration'  => ['required', 'date', 'after:date_fabrication'],
            'fabricant'        => ['required', 'string', 'max:160'],
            'quantite'         => ['required', 'integer', 'min:1', 'max:'.config('afrishop.qr.quantite_max_par_lot', 10000)],
            'description'      => ['nullable', 'string', 'max:1000'],
        ], [
            'produit_id.exists'     => 'Ce produit n’appartient pas à votre boutique.',
            'date_expiration.after' => 'La date d’expiration doit suivre la date de fabrication.',
        ]);

        $produit = Produit::findOrFail($donnees['produit_id']);
        if (! $produit->tracable) {
            return response()->json(['message' => "« {$produit->nom} » n’est pas marqué « traçable » : "
                .'activez la traçabilité sur sa fiche avant de demander des étiquettes.'], 422);
        }

        $lot = LotQr::create([
            'produit_id'       => $produit->id,
            'boutique_id'      => $boutique->id,
            'pays_id'          => $boutique->pays_id,
            'reference'        => LotQr::prochaineReference(),
            'date_fabrication' => $donnees['date_fabrication'],
            'date_expiration'  => $donnees['date_expiration'],
            'fabricant'        => $donnees['fabricant'],
            'numero_debut'     => 0,
            'quantite'         => $donnees['quantite'],
            'description'      => $donnees['description'] ?? null,
            'statut'           => StatutLotQr::Demande,
            'demande_par_id'   => $r->user()->id,
            'cree_par_id'      => $r->user()->id,
        ]);

        return response()->json(['message' => "Demande {$lot->reference} envoyée à Afrishop.", 'lot' => $this->resume($lot)], 201);
    }

    public function mesLots(Request $r): JsonResponse
    {
        $boutique = $r->user()?->boutique;
        abort_unless($boutique, 403, 'Aucune boutique rattachée à ce compte.');

        return response()->json([
            'data' => LotQr::with('produit:id,nom')->where('boutique_id', $boutique->id)
                ->orderByDesc('id')->limit(100)->get()->map(fn (LotQr $l) => $this->resume($l)),
            'produits_tracables' => Produit::where('boutique_id', $boutique->id)->where('tracable', true)
                ->orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    // -----------------------------------------------------------------

    private function resume(LotQr $l): array
    {
        $avecCodes = ! in_array($l->statut, [StatutLotQr::Demande, StatutLotQr::Refuse], true);

        return [
            'id'               => $l->id,
            'reference'        => $l->reference,
            'produit'          => $l->produit?->nom,
            'boutique'         => $l->boutique?->nom,
            'statut'           => $l->statut->value,
            'statut_libelle'   => $l->statut->libelle(),
            'quantite'         => $l->quantite,
            'fabricant'        => $l->fabricant,
            'date_fabrication' => $l->date_fabrication?->toDateString(),
            'date_expiration'  => $l->date_expiration?->toDateString(),
            'motif_refus'      => $l->motif_refus,
            'scannes'          => $avecCodes ? $l->codes()->where('nb_scans', '>', 0)->count() : 0,
        ];
    }

    private function urlPlanche(LotQr $lot): string
    {
        return URL::temporarySignedRoute('admin.lots.planche', now()->addMinutes(30), ['lot' => $lot->id]);
    }
}

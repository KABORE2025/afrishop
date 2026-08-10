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
use Illuminate\Http\Request;

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
    public function creer(CreerLotQrRequest $request)
    {
        $lot = $this->lots->creerLot(
            donnees: $request->validated(),
            creeParId: $request->user()->id,
            demandeParId: $request->input('demande_par_id'),
        );

        return response()->json([
            'reference'    => $lot->reference,
            'quantite'     => $lot->quantite,
            'premier_code' => $lot->codes()->orderBy('numero')->first()->code_lisible,
            'dernier_code' => $lot->codes()->orderByDesc('numero')->first()->code_lisible,
            'url_planche'  => route('admin.lots.planche', $lot),
        ], 201);
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
            $qr = Builder::create()
                ->data($code->urlVerification())
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                ->size(220)
                ->margin(6)
                ->build();

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
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LotQrService;
use Illuminate\Http\Request;

/**
 * =====================================================================
 *  PAGE PUBLIQUE DE VÉRIFICATION — ce que voit le client qui scanne
 * =====================================================================
 *  Route volontairement très courte : /v/{jeton}
 *  Une URL courte donne un QR code moins dense, donc plus facile à lire
 *  par un téléphone d'entrée de gamme, sur une étiquette imprimée petit
 *  et parfois abîmée. Ce détail conditionne le taux de scan réussi.
 *
 *  Cette route ne demande AUCUNE authentification et ne doit jamais en
 *  demander : un client sur un marché ne créera pas de compte pour
 *  vérifier un pot de karité.
 * =====================================================================
 */
class VerificationQrController extends Controller
{
    public function __construct(private LotQrService $lots) {}

    /**
     * GET /v/{jeton}      → page HTML lisible par le client
     * GET /api/v/{jeton}  → même contenu en JSON
     */
    public function verifier(Request $request, string $jeton)
    {
        $resultat = $this->lots->verifier(
            jeton: $jeton,
            ip:    $request->ip(),
            agent: $request->userAgent(),
            ville: $request->header('CF-IPCity'),  // fourni par le CDN si présent
        );

        // Code inconnu : on renvoie 404 côté API, mais une vraie page
        // côté navigateur — un client qui tombe sur une erreur technique
        // ne comprendrait pas qu'on vient de lui dire « contrefaçon ».
        if ($resultat['verdict'] === 'inconnu') {
            return $request->expectsJson()
                ? response()->json(['verdict' => 'inconnu', 'message' => $resultat['message']], 404)
                : response()->view('verification', $resultat, 404);
        }

        $code = $resultat['code'];
        $lot  = $resultat['lot'];

        $donnees = [
            'verdict' => $resultat['verdict'],
            'message' => $resultat['message'],
            'produit' => [
                'nom'         => $lot->produit->nom,
                'description' => $lot->produit->description,
                'boutique'    => $lot->produit->boutique->nom,
                'ville'       => $lot->produit->boutique->ville,
            ],
            'lot' => [
                'reference'        => $lot->reference,
                'fabricant'        => $lot->fabricant,
                'date_fabrication' => $lot->date_fabrication->format('d/m/Y'),
                'date_expiration'  => $lot->date_expiration->format('d/m/Y'),
                'jours_restants'   => $lot->joursAvantExpiration(),
                'mentions'         => $lot->description,
            ],
            'etiquette' => [
                'code_lisible' => $code->code_lisible,

                // Compteur public : voir cette valeur dissuade la copie
                // d'étiquette. Un client qui lit « scanné 63 fois depuis
                // 9 villes » sur un produit qu'il vient d'acheter neuf
                // comprend immédiatement qu'il y a un problème.
                'nb_scans'     => $code->nb_scans,
                'villes'       => $code->nbVillesDistinctes(),
                'premier_scan' => $code->premier_scan_le?->format('d/m/Y'),
            ],
        ];

        return $request->expectsJson()
            ? response()->json($donnees)
            : response()->view('verification', $donnees);
    }
}

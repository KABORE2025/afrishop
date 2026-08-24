<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransporteurResource;
use App\Models\Transporteur;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * =====================================================================
 *  RÉFÉRENTIEL DES TRANSPORTEURS
 * =====================================================================
 *  Manquait complètement : l'action « expédier » accepte un
 *  `transporteur_id`, mais aucun endpoint ne permettait d'obtenir la
 *  liste. Le menu déroulant de l'écran vendeur n'avait pas de source.
 *
 *  Réservé aux comptes connectés : la liste des partenaires logistiques
 *  et leurs numéros n'a pas à être publique.
 * =====================================================================
 */
class TransporteurController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        $requete = Transporteur::query()->where('actif', true);

        if ($r->filled('pays_id')) {
            $requete->where('pays_id', $r->integer('pays_id'));
        }

        /*
         * `?encaisse_especes=1` restreint aux transporteurs habilités à
         * collecter de l'argent. C'est le filtre à utiliser quand la
         * commande est en paiement à la livraison : proposer un livreur
         * non habilité produirait une livraison sans encaissement, donc
         * une vente impayée.
         */
        if ($r->boolean('encaisse_especes')) {
            $requete->where('encaisse_especes', true);
        }

        return TransporteurResource::collection($requete->orderBy('nom')->get());
    }
}

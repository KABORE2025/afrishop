<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 *  UNE EXPÉDITION — SANS LE CODE DE LIVRAISON
 * =====================================================================
 *  ⚠ LA LIGNE LA PLUS IMPORTANTE DE CE FICHIER EST CELLE QUI N'Y EST PAS.
 *
 *  `code_livraison` n'est PAS exposé, et ce n'est pas un oubli.
 *
 *  Ce code à usage unique est envoyé au CLIENT par SMS. À la remise du
 *  colis, le client le dicte, et c'est sa saisie qui prouve que la
 *  livraison a réellement eu lieu. Si la boutique peut lire ce code
 *  dans sa propre interface, elle peut marquer « livrée » une commande
 *  qu'elle n'a jamais remise — et déclencher la libération des fonds
 *  sans que le client ait rien reçu.
 *
 *  Le contrôle ne vaut que si celui qui doit être contrôlé ne connaît
 *  pas la réponse. Toute évolution de cette classe doit respecter cela.
 *
 *  (Une console d'administration Afrishop pourra le lire un jour, pour
 *  arbitrer un litige : ce sera une autre Resource, avec un autre
 *  contrôle d'accès. Pas celle-ci.)
 * =====================================================================
 */
class ExpeditionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'statut'         => $this->statut,
            'code_suivi'     => $this->code_suivi,
            'transporteur'   => new TransporteurResource($this->whenLoaded('transporteur')),
            'expedie_le'     => $this->expedie_le?->toIso8601String(),
            'livre_le'       => $this->livre_le?->toIso8601String(),

            /* Le code EXISTE-t-il ? oui/non. Sa valeur, jamais. Le
             * vendeur a besoin de savoir qu'un code a bien été généré
             * et envoyé, pas de savoir lequel. */
            'code_livraison_genere' => $this->code_livraison !== null,
            'code_valide_le'        => $this->code_valide_le?->toIso8601String(),
        ];
    }
}

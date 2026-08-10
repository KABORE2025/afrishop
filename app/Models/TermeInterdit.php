<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vocabulaire proscrit sur une fiche produit.
 *
 * En table et non en dur : la liste s'allonge à l'usage, et l'allonger
 * ne doit pas exiger un déploiement.
 *
 * `bloquant`  refus dès la soumission — le vendeur corrige pendant
 *             qu'il a sa fiche sous les yeux.
 * `a_verifier` signalé au relecteur, qui tranche. « ne traite pas la
 *             peau » contient « traite » et reste innocent.
 */
class TermeInterdit extends Model
{
    protected $table = 'termes_interdits';
    protected $guarded = ['id'];

    public $timestamps = false;
}

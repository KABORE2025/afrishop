<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une recherche qui n'a rien donné.
 *
 * La table la plus rentable du schéma, et la plus discrète. Une
 * recherche vide ne produit ni erreur, ni réclamation, ni ticket : le
 * client s'en va en pensant que la plateforme est vide. Cette table est
 * la seule chose qui rende la panne visible — et chaque ligne est un
 * synonyme qu'il suffit d'ajouter pour rouvrir une vente, pour tous les
 * suivants et pas seulement pour celui-là.
 */
class RechercheInfructueuse extends Model
{
    protected $table = 'recherches_infructueuses';
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'premiere_fois' => 'datetime',
        'derniere_fois' => 'datetime',
    ];
}

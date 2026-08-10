<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Équivalence entre un terme du catalogue et la façon dont les clients
 * l'écrivent : synonyme, nom local, traduction, orthographe.
 *
 * L'équivalence est SYMÉTRIQUE : on interroge la table dans les deux
 * sens. La déclarer deux fois créerait deux vérités à maintenir, donc
 * tôt ou tard une divergence — un jour « da » trouverait bissap sans
 * que « bissap » trouve da, sans que personne sache pourquoi.
 */
class TermeRecherche extends Model
{
    protected $table = 'termes_recherche';
    protected $guarded = ['id'];

    protected $casts = ['actif' => 'boolean'];
}

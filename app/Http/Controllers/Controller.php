<?php

namespace App\Http\Controllers;

/**
 * =====================================================================
 *  CLASSE DE BASE DES CONTRÔLEURS
 * =====================================================================
 *  Depuis Laravel 11, cette classe est volontairement VIDE. Les traits
 *  qu'elle portait autrefois — AuthorizesRequests, ValidatesRequests —
 *  ne sont plus inclus par défaut : on les ajoute au cas par cas, là où
 *  ils servent réellement.
 *
 *  Les contrôleurs d'Afrishop n'en ont besoin d'aucun :
 *    · la validation se fait par des Form Requests dédiées, qui gardent
 *      les règles hors du contrôleur et permettent de les tester seules ;
 *    · l'autorisation passe par les Policies, déclarées dans app/Policies.
 *
 *  Si vous ajoutez un jour un contrôleur qui appelle `$this->authorize()`
 *  ou `$this->validate()`, décommentez le trait correspondant plutôt que
 *  de le remettre partout : ce qui n'est pas utilisé n'a pas à être
 *  chargé.
 * =====================================================================
 */
abstract class Controller
{
    // use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    // use \Illuminate\Foundation\Validation\ValidatesRequests;
}

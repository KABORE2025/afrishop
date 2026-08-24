<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * =====================================================================
 *  COQUILLES DE L'ESPACE VENDEUR
 * =====================================================================
 *  Ces méthodes ne font QUE rendre un gabarit. Aucune requête en base,
 *  aucune donnée : tout est chargé ensuite par le navigateur en appelant
 *  l'API.
 *
 *  POURQUOI AUCUN MIDDLEWARE `auth` ICI
 *  Ce projet n'a pas d'authentification par session — la seule qui
 *  existe est celle par jeton Sanctum, utilisée par l'API. Poser
 *  `auth` sur ces routes exigerait un second système de connexion,
 *  côté session, pour garder deux fois la même porte.
 *
 *  Ce n'est pas un trou de sécurité, à une condition qui est remplie
 *  ici : CES PAGES NE CONTIENNENT AUCUNE DONNÉE. Un visiteur sans jeton
 *  qui atteindrait l'URL verrait une coquille vide, puis serait renvoyé
 *  vers la connexion. Chaque octet affiché vient de l'API, et l'API est
 *  protégée par `auth:sanctum` et `role:vendeur`.
 *
 *  La règle à ne jamais enfreindre : si un jour une de ces vues reçoit
 *  une variable Blade contenant des données de boutique, il faudra une
 *  vraie garde côté serveur. Tant qu'elles sont vides, il n'en faut pas.
 * =====================================================================
 */
class EspaceVendeurController extends Controller
{
    public function connexion(): View
    {
        return view('vendeur.connexion');
    }

    public function tableauDeBord(): View
    {
        return view('vendeur.tableau-de-bord');
    }

    public function commandes(): View
    {
        return view('vendeur.commandes');
    }
}

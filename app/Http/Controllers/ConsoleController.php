<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Coquilles de la console Afrishop.
 *
 * Mêmes principes que EspaceVendeurController : ces méthodes ne rendent
 * qu'un gabarit, sans aucune donnée. La garde est côté API
 * (`auth:sanctum` + `role:admin`), pas ici — le projet n'a pas
 * d'authentification par session.
 *
 * Le jour où l'une de ces vues reçoit une variable Blade contenant des
 * données, il faudra une vraie garde côté serveur. C'est d'autant plus
 * vrai ici : la console voit les litiges, les montants et les
 * coordonnées de toutes les boutiques.
 */
class ConsoleController extends Controller
{
    public function tableauDeBord(): View { return view('console.tableau-de-bord'); }
    public function candidatures(): View  { return view('console.candidatures'); }
    public function produits(): View      { return view('console.produits'); }
    public function litiges(): View       { return view('console.litiges'); }
    public function reversements(): View  { return view('console.reversements'); }
    public function commandes(): View     { return view('console.commandes'); }
    public function sequestre(): View     { return view('console.sequestre'); }
    public function administrateurs(): View { return view('console.administrateurs'); }
}

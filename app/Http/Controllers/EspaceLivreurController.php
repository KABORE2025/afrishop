<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/** Coquilles de l'espace livreur, alimentées uniquement par l'API protégée. */
class EspaceLivreurController extends Controller
{
    public function connexion(): View
    {
        return view('livreur.connexion');
    }

    public function commandes(): View
    {
        return view('livreur.commandes');
    }
}

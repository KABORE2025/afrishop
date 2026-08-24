<?php

use App\Http\Controllers\Api\VerificationQrController;
use App\Http\Controllers\EspaceVendeurController;
use App\Http\Controllers\VitrineController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes web
|--------------------------------------------------------------------------
| Une seule route côté navigateur : la page de vérification d'étiquette.
|
| Elle est en Blade et non en JavaScript, délibérément. Le client qui
| scanne est dans la rue, sur un téléphone d'entrée de gamme, avec une
| connexion 2G ou 3G instable. Une page rendue par le serveur s'affiche
| en une requête ; une application JavaScript demanderait de charger un
| bundle avant de pouvoir afficher quoi que ce soit — et échouerait
| précisément dans les conditions où ce dispositif doit fonctionner.
|
| L'URL est aussi courte que possible : /v/{jeton}. Moins de caractères
| à encoder, donc un QR moins dense, donc plus facile à lire sur une
| étiquette de deux centimètres, imprimée en série et parfois abîmée.
*/

Route::get('/v/{jeton}', [VerificationQrController::class, 'verifier'])
    ->middleware('throttle:60,1')
    ->name('verification.qr');

/*
| VITRINE — rendue par le serveur, sans JavaScript.
| Voir BO-01 du dossier : le réseau est la contrainte de conception,
| pas une hypothèse défavorable.
*/
Route::get('/', [VitrineController::class, 'index'])->name('vitrine');
Route::get('/p/{slug}', [VitrineController::class, 'produit'])->name('produit');

/*
|--------------------------------------------------------------------------
| ESPACE VENDEUR — coquilles vides remplies par l'API
|--------------------------------------------------------------------------
| Ces routes n'ont PAS de middleware `auth`, et ce n'est pas un oubli.
|
| Le projet n'a pas d'authentification par session : la seule qui existe
| est celle par jeton Sanctum, utilisée par l'API. Poser une garde ici
| exigerait un second système de connexion pour fermer deux fois la même
| porte.
|
| Ce n'est pas un trou de sécurité à une condition, remplie ici : CES
| PAGES NE CONTIENNENT AUCUNE DONNÉE. Elles arrivent vides ; tout ce qui
| s'y affiche vient de l'API, qui est protégée par `auth:sanctum` et
| `role:vendeur`. Un visiteur sans jeton voit une coquille, puis est
| renvoyé vers la connexion.
|
| RÈGLE À NE JAMAIS ENFREINDRE : le jour où l'une de ces vues reçoit une
| variable Blade contenant des données de boutique, il faut une vraie
| garde côté serveur. Tant qu'elles sont vides, il n'en faut pas.
*/
Route::prefix('vendeur')->name('vendeur.')->group(function () {
    Route::get('/connexion', [EspaceVendeurController::class, 'connexion'])->name('connexion');
    Route::get('/',          [EspaceVendeurController::class, 'tableauDeBord'])->name('tableau');
    Route::get('/commandes', [EspaceVendeurController::class, 'commandes'])->name('commandes');
});

<?php

use App\Http\Controllers\Api\VerificationQrController;
use App\Http\Controllers\CommandeWebController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\EspaceLivreurController;
use App\Http\Controllers\EspaceVendeurController;
use App\Http\Controllers\PanierController;
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
| PANIER ET COMMANDE — rendu serveur, zéro JavaScript
|--------------------------------------------------------------------------
| Le panier vit en SESSION, pas en base : un visiteur anonyme qui
| parcourt le catalogue n'a pas à écrire des lignes en base. La table
| `paniers` du modèle reste utile pour la relance des paniers
| abandonnés, mais cela demande d'abord un moyen de recontacter le
| client — tant qu'on ne l'a pas, écrire en base ne remplirait que le
| disque.
|
| Chaque action est un POST suivi d'une redirection : sans cela, un
| client qui rafraîchit après un ajout se retrouve avec deux articles.
*/
Route::get('/panier', [PanierController::class, 'voir'])->name('panier');
Route::post('/panier/ajouter', [PanierController::class, 'ajouter'])->name('panier.ajouter');
Route::post('/panier/modifier', [PanierController::class, 'modifier'])->name('panier.modifier');
Route::post('/panier/vider', [PanierController::class, 'vider'])->name('panier.vider');

Route::get('/commander', [CommandeWebController::class, 'formulaire'])->name('commander');
Route::post('/commander', [CommandeWebController::class, 'enregistrer'])->name('commander.enregistrer');

// Accessible par la seule référence : un client sans compte doit pouvoir
// y revenir depuis son SMS. En contrepartie, cette page ne montre rien
// de sensible — une référence peut être devinée ou partagée.
Route::get('/commande/{reference}', [CommandeWebController::class, 'confirmee'])
    ->name('commande.confirmee');

/*
 * Relance d'un paiement en attente ou refusé. En POST, pas en GET : un
 * lien cliquable relancerait un prélèvement au moindre passage d'un
 * aspirateur de pages, et un rafraîchissement le rejouerait.
 */
Route::post('/commande/{reference}/payer', [CommandeWebController::class, 'payer'])
    ->name('commande.payer');

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
    Route::get('/', [EspaceVendeurController::class, 'tableauDeBord'])->name('tableau');
    Route::get('/commandes', [EspaceVendeurController::class, 'commandes'])->name('commandes');
    Route::get('/livreurs', [EspaceVendeurController::class, 'livreurs'])->name('livreurs');
    Route::get('/produits', [EspaceVendeurController::class, 'produits'])->name('produits');
    Route::get('/reversements', [EspaceVendeurController::class, 'reversements'])->name('reversements');
});

// Espace livreur : même modèle que l'espace vendeur, données chargées
// exclusivement via l'API protégée par Sanctum et le rôle `livreur`.
Route::prefix('livreur')->name('livreur.')->group(function () {
    Route::get('/connexion', [EspaceLivreurController::class, 'connexion'])->name('connexion');
    Route::get('/', [EspaceLivreurController::class, 'commandes'])->name('commandes');
});

/*
|--------------------------------------------------------------------------
| CONSOLE AFRISHOP
|--------------------------------------------------------------------------
| Même principe que l'espace vendeur : coquilles vides, aucune donnée
| côté serveur, l'API fait la garde (`auth:sanctum` + `role:admin`).
*/
Route::prefix('console')->name('console.')->group(function () {
    Route::get('/', [ConsoleController::class, 'tableauDeBord'])->name('tableau');
    Route::get('/candidatures', [ConsoleController::class, 'candidatures'])->name('candidatures');
    Route::get('/produits', [ConsoleController::class, 'produits'])->name('produits');
    Route::get('/litiges', [ConsoleController::class, 'litiges'])->name('litiges');
    Route::get('/reversements', [ConsoleController::class, 'reversements'])->name('reversements');
});

<?php

use App\Http\Controllers\Api\VerificationQrController;
use App\Http\Controllers\BoiteSmsTestController;
use App\Http\Controllers\CandidatureWebController;
use App\Http\Controllers\ClientColisController;
use App\Http\Controllers\Api\LotQrController;
use App\Http\Controllers\CommandeWebController;
use App\Http\Controllers\ProtectionClientController;
use App\Http\Controllers\SuiviCommandeController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\EspaceLivreurController;
use App\Http\Controllers\EspaceVendeurController;
use App\Http\Controllers\PanierController;
use App\Http\Controllers\PhotoLitigeController;
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
Route::post('/commander', [CommandeWebController::class, 'enregistrer'])
    ->middleware('throttle:commande')->name('commander.enregistrer');

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
    ->middleware('throttle:commande')->name('commande.payer');

/*
 * Fenêtre de protection (72 h après la remise) : confirmer la bonne
 * réception ou signaler un problème. Le téléphone de la commande est
 * exigé en plus de la référence — voir ProtectionClientController.
 */
Route::post('/commande/{reference}/colis/{colis}/signaler', [ProtectionClientController::class, 'signaler'])
    ->middleware('throttle:protection')->name('commande.signaler');
Route::post('/commande/{reference}/colis/{colis}/confirmer', [ProtectionClientController::class, 'confirmer'])
    ->middleware('throttle:protection')->name('commande.confirmer');

/*
 * Suivi sans compte : numéro de commande + téléphone. Le téléphone
 * prouvé est retenu en session pour cette commande (VerifieTelephoneCommande).
 */
Route::get('/suivi', [SuiviCommandeController::class, 'formulaire'])->name('suivi');

// Candidature vendeur : traitée à la main dans la console (Candidatures).
Route::get('/ouvrir-ma-boutique', [CandidatureWebController::class, 'formulaire'])->name('devenir-vendeur');
Route::post('/ouvrir-ma-boutique', [CandidatureWebController::class, 'enregistrer'])
    ->middleware('throttle:formulaire')->name('devenir-vendeur.enregistrer');
Route::post('/suivi', [SuiviCommandeController::class, 'rechercher'])
    ->middleware('throttle:suivi')->name('suivi.rechercher');
Route::post('/commande/{reference}/colis/{colis}/renvoyer-code', [SuiviCommandeController::class, 'renvoyerCode'])
    ->middleware('throttle:protection')->name('commande.renvoyer-code');
// En POST : le formulaire porte le téléphone quand la session ne l'a pas encore.
Route::match(['get', 'post'], '/commande/{reference}/recu', [SuiviCommandeController::class, 'recu'])
    ->middleware('throttle:protection')->name('commande.recu');

// Annuler un colis pas encore préparé, demander un retour, laisser un avis.
Route::post('/commande/{reference}/colis/{colis}/annuler', [ClientColisController::class, 'annuler'])
    ->middleware('throttle:protection')->name('commande.annuler');
Route::post('/commande/{reference}/colis/{colis}/retour', [ClientColisController::class, 'retour'])
    ->middleware('throttle:protection')->name('commande.retour');
Route::post('/commande/{reference}/colis/{colis}/avis', [ClientColisController::class, 'avis'])
    ->middleware('throttle:protection')->name('commande.avis');

// Planche d'étiquettes QR : lien SIGNÉ et temporaire émis par la console
// (LotQrController::lienPlanche). Sans signature, n'importe qui
// connaissant l'adresse imprimerait de vraies étiquettes.
Route::get('/console/lots-qr/{lot}/planche', [LotQrController::class, 'planche'])
    ->middleware('signed')->name('admin.lots.planche');

// Comptes : pages sans données, l'API fait la garde.
Route::view('/mot-de-passe-oublie', 'compte.mot-de-passe-oublie')->name('mot-de-passe-oublie');
Route::view('/compte/mot-de-passe', 'compte.mot-de-passe')->name('compte.mot-de-passe');

// Photos d'un litige : disque privé, lien signé et temporaire (30 min)
// émis par l'API admin ou vendeur. Voir PhotoLitigeController.
Route::get('/litiges/photos/{media}/{taille}', PhotoLitigeController::class)
    ->middleware('signed')->name('litige.photo');

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
    Route::view('/boutique', 'vendeur.boutique')->name('boutique');
    Route::view('/etiquettes', 'vendeur.etiquettes')->name('etiquettes');
});

// Espace livreur : même modèle que l'espace vendeur, données chargées
// exclusivement via l'API protégée par Sanctum et le rôle `livreur`.
Route::prefix('livreur')->name('livreur.')->group(function () {
    Route::get('/connexion', [EspaceLivreurController::class, 'connexion'])->name('connexion');
    Route::get('/', [EspaceLivreurController::class, 'commandes'])->name('commandes');
    // Activation d'un nouveau livreur avec le code reçu par SMS.
    Route::view('/activer', 'livreur.activer')->name('activer');
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
    Route::get('/commandes', [ConsoleController::class, 'commandes'])->name('commandes');
    Route::get('/sequestre', [ConsoleController::class, 'sequestre'])->name('sequestre');
    Route::get('/administrateurs', [ConsoleController::class, 'administrateurs'])->name('administrateurs');
    Route::view('/boutiques', 'console.boutiques')->name('boutiques');
    Route::view('/etiquettes', 'console.etiquettes')->name('etiquettes');
    Route::view('/reglages', 'console.reglages')->name('reglages');
    Route::view('/journal', 'console.journal')->name('journal');
    Route::view('/avis', 'console.avis')->name('avis');
    Route::get('/reversements', [ConsoleController::class, 'reversements'])->name('reversements');
});

/*
|--------------------------------------------------------------------------
| BOÎTE SMS DE TEST — développement uniquement
|--------------------------------------------------------------------------
| Affiche les SMS simulés, codes de livraison compris. En production, ces
| routes ne sont PAS déclarées ; ailleurs, le contrôleur exige en plus
| APP_ENV=local, SMS_DRIVER=journal et un accès depuis la machine elle-même
| (voir BoiteSmsTestController).
*/
if (! app()->isProduction()) {
    Route::get('/dev/sms', [BoiteSmsTestController::class, 'index'])->name('dev.sms');
    Route::post('/dev/sms/envoyer', [BoiteSmsTestController::class, 'envoyer'])->name('dev.sms.envoyer');
}

<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogueController;
use App\Http\Controllers\Api\CommandeController;
use App\Http\Controllers\Api\LotQrController;
use App\Http\Controllers\Api\PaiementWebhookController;
use App\Http\Controllers\Api\VendeurController;
use App\Http\Controllers\Api\VerificationQrController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Afrishop
|--------------------------------------------------------------------------
| Trois niveaux d'accès, du plus ouvert au plus restreint :
|
|   1. PUBLIC     — la vitrine et la vérification QR. Aucun compte requis.
|                   Un client sur un marché ne créera pas de compte pour
|                   vérifier un pot de karité.
|   2. VENDEUR    — la boutique gère SES commandes et SES produits.
|   3. ADMIN      — la console Afrishop : validation, litiges, virements,
|                   génération des lots QR.
|
| Le versionnage (/api/v1) n'est pas mis en place au démarrage : tant que
| le front et l'API sont livrés ensemble, il ajoute de la cérémonie sans
| bénéfice. À introduire le jour où une application mobile publiée sur les
| stores consommera cette API — car on ne pourra plus forcer sa mise à jour.
*/

// ---------------------------------------------------------------------
// 0. AUTHENTIFICATION
// ---------------------------------------------------------------------
// Un compte créé via /auth/inscription est toujours un CLIENT : devenir
// vendeur passe par /candidatures, traitée par un administrateur.
Route::post('/auth/inscription',   [AuthController::class, 'inscrire']);
Route::post('/auth/connexion',     [AuthController::class, 'connecter']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/deconnexion', [AuthController::class, 'deconnecter']);
    Route::get('/auth/moi',          [AuthController::class, 'moi']);
});

// ---------------------------------------------------------------------
// 1. PUBLIC
// ---------------------------------------------------------------------
Route::get('/categories',              [CatalogueController::class, 'categories']);
Route::get('/boutiques',               [CatalogueController::class, 'boutiques']);
Route::get('/produits',                [CatalogueController::class, 'produits']);
Route::get('/produits/{produit}/offres', [CatalogueController::class, 'offres']);

Route::post('/commandes',              [CommandeController::class, 'creer']);
Route::get('/commandes/suivi',         [CommandeController::class, 'suivi']);   // ?telephone=...&reference=...
Route::post('/candidatures',           [CommandeController::class, 'candidater']);

// Vérification d'une étiquette, en JSON. La version HTML est dans web.php.
// Limitée à 60 requêtes par minute et par IP : un contrefacteur qui
// voudrait tester des jetons au hasard doit être ralenti.
Route::get('/v/{jeton}', [VerificationQrController::class, 'verifier'])
    ->middleware('throttle:60,1');

// ---------------------------------------------------------------------
// 2. VENDEUR
// ---------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:vendeur'])->prefix('vendeur')->group(function () {
    Route::get('/commandes',                    [VendeurController::class, 'commandes']);
    Route::post('/commandes/{sousCommande}/expedier', [VendeurController::class, 'expedier']);
    Route::post('/commandes/{sousCommande}/livrer',   [VendeurController::class, 'livrer']);
    Route::get('/produits',                     [VendeurController::class, 'produits']);
    Route::post('/produits',                    [VendeurController::class, 'creerProduit']);
    Route::get('/reversements',                 [VendeurController::class, 'reversements']);

    // Le vendeur DEMANDE un lot d'étiquettes ; il ne le génère pas.
    // Lui laisser générer ses propres codes reviendrait à lui laisser
    // fabriquer ses propres preuves d'authenticité.
    Route::post('/lots-qr/demandes',            [VendeurController::class, 'demanderLotQr']);
});

// ---------------------------------------------------------------------
// 3. ADMINISTRATION AFRISHOP
// ---------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::post('/lots-qr',                     [LotQrController::class, 'creer']);
    Route::get('/lots-qr/{lot}/planche',        [LotQrController::class, 'planche'])->name('admin.lots.planche');
    Route::get('/lots-qr/{lot}/statistiques',   [LotQrController::class, 'statistiques']);
    Route::post('/lots-qr/{lot}/rappel',        [LotQrController::class, 'rappeler']);
});

// ---------------------------------------------------------------------
// 4. WEBHOOKS PSP
// ---------------------------------------------------------------------
// Hors auth:sanctum par nature : c'est le prestataire de paiement qui
// appelle, pas un utilisateur de la plateforme. L'authenticité est
// vérifiée par signature (PaymentGatewayInterface::verifierSignatureWebhook()),
// pas par un jeton Sanctum.
Route::post('/webhooks/paiement/{prestataire}', [PaiementWebhookController::class, 'recevoir']);

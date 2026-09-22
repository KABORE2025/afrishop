<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AgentRemiseController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogueController;
use App\Http\Controllers\Api\CommandeController;
use App\Http\Controllers\Api\LivreurController;
use App\Http\Controllers\Api\LotQrController;
use App\Http\Controllers\Api\PaiementWebhookController;
use App\Http\Controllers\Api\TransporteurController;
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
Route::post('/auth/inscription', [AuthController::class, 'inscrire']);
Route::post('/auth/connexion', [AuthController::class, 'connecter']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/deconnexion', [AuthController::class, 'deconnecter']);
    Route::get('/auth/moi', [AuthController::class, 'moi']);
});

// ---------------------------------------------------------------------
// 1. PUBLIC
// ---------------------------------------------------------------------
Route::get('/categories', [CatalogueController::class, 'categories']);
Route::get('/boutiques', [CatalogueController::class, 'boutiques']);
Route::get('/produits', [CatalogueController::class, 'produits']);
Route::get('/produits/{produit}/offres', [CatalogueController::class, 'offres']);

Route::post('/commandes', [CommandeController::class, 'creer']);
Route::get('/commandes/suivi', [CommandeController::class, 'suivi']);   // ?telephone=...&reference=...
Route::post('/candidatures', [CommandeController::class, 'candidater']);
Route::post('/agents-remise/activer', [AgentRemiseController::class, 'activer']);

// Référentiel des transporteurs — alimente le choix du transporteur à
// l'expédition. Réservé aux comptes connectés : la liste des partenaires
// logistiques et leurs numéros n'a pas à être publique.
// `?encaisse_especes=1` restreint aux transporteurs habilités à collecter
// de l'argent, seuls utilisables en paiement à la livraison.
Route::middleware('auth:sanctum')->get('/transporteurs', [TransporteurController::class, 'index']);

// Vérification d'une étiquette, en JSON. La version HTML est dans web.php.
// Limitée à 60 requêtes par minute et par IP : un contrefacteur qui
// voudrait tester des jetons au hasard doit être ralenti.
Route::get('/v/{jeton}', [VerificationQrController::class, 'verifier'])
    ->middleware('throttle:60,1');

// ---------------------------------------------------------------------
// 2. VENDEUR
// ---------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:vendeur'])->prefix('vendeur')->group(function () {
    // Les indicateurs de l'espace vendeur. Calculés côté serveur : la
    // liste des commandes étant paginée, un total additionné par le front
    // porterait sur 25 lignes en croyant porter sur toutes.
    Route::get('/tableau-de-bord', [VendeurController::class, 'tableauDeBord']);

    Route::get('/commandes', [VendeurController::class, 'commandes']);
    Route::get('/livreurs', [VendeurController::class, 'livreurs']);
    Route::get('/agents-remise', [AgentRemiseController::class, 'index']);
    Route::post('/agents-remise/rattachements', [AgentRemiseController::class, 'rattacher']);
    Route::post('/agents-remise/invitations', [AgentRemiseController::class, 'inviter']);
    Route::post('/commandes/{sousCommande}/expedier', [VendeurController::class, 'expedier']);
    // Retrait en boutique : deux temps, comme l'expédition/livraison,
    // mais sans transporteur ni livreur — le vendeur remet lui-même.
    Route::post('/commandes/{sousCommande}/preparer-retrait', [VendeurController::class, 'preparerRetrait']);
    Route::post('/commandes/{sousCommande}/confirmer-retrait', [VendeurController::class, 'confirmerRetrait']);

    Route::get('/produits', [VendeurController::class, 'produits']);
    Route::post('/produits', [VendeurController::class, 'creerProduit']);
    // Corriger un prix ou une description. Une modification de FOND
    // (nom, description, catégorie) fait repasser la fiche en modération.
    Route::put('/produits/{produit}', [VendeurController::class, 'modifierProduit']);
    // Le geste le plus fréquent d'une boutique : mettre à jour le stock.
    Route::patch('/variantes/{variante}', [VendeurController::class, 'majVariante']);

    // Photos et vidéos. Un produit sans image ne se vend pas : c'est
    // le premier manque que signalent les vendeurs.
    Route::post('/produits/{produit}/medias', [VendeurController::class, 'ajouterMedia']);
    Route::delete('/medias/{media}', [VendeurController::class, 'supprimerMedia']);

    Route::get('/reversements', [VendeurController::class, 'reversements']);

    // Le vendeur DEMANDE un lot d'étiquettes ; il ne le génère pas.
    // Lui laisser générer ses propres codes reviendrait à lui laisser
    // fabriquer ses propres preuves d'authenticité.
    Route::post('/lots-qr/demandes', [VendeurController::class, 'demanderLotQr']);
});

// Le livreur est le seul acteur qui peut présenter et valider le code
// reçu par le client. Le vendeur ne dispose d'aucune route de validation.
Route::middleware(['auth:sanctum', 'role:livreur'])->prefix('livreur')->group(function () {
    Route::get('/tableau-de-bord', [LivreurController::class, 'tableauDeBord']);
    // ?statut[]=expediee (défaut) pour le travail du jour, ou
    // ?statut[]=livree&statut[]=retour_expediteur pour l'historique.
    Route::get('/commandes', [LivreurController::class, 'commandes']);
    Route::post('/commandes/{sousCommande}/livrer', [LivreurController::class, 'livrer']);
    // Échec de remise : client absent, adresse introuvable, colis
    // refusé. Sans elle, un échec n'avait aucune issue (voir la méthode).
    Route::post('/commandes/{sousCommande}/echec', [LivreurController::class, 'signalerEchec']);
});

// ---------------------------------------------------------------------
// 3. ADMINISTRATION AFRISHOP
// ---------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {

    // Ce qui attend une décision humaine — pas un chiffre d'affaires.
    Route::get('/tableau-de-bord', [AdminController::class, 'tableauDeBord']);

    // Candidatures. Accepter CRÉE la boutique et rattache son gérant ;
    // refuser exige un motif, parce qu'un refus muet est incontestable.
    Route::get('/candidatures', [AdminController::class, 'candidatures']);
    Route::post('/candidatures/{candidature}/accepter', [AdminController::class, 'accepterCandidature']);
    Route::post('/candidatures/{candidature}/refuser', [AdminController::class, 'refuserCandidature']);

    // Litiges. L'arbitrage est le SEUL endroit de la console qui déplace
    // de l'argent, et il le fait par SequestreService — jamais par une
    // écriture composée à la main.
    Route::get('/litiges', [AdminController::class, 'litiges']);
    Route::post('/litiges/{litige}/arbitrer', [AdminController::class, 'arbitrerLitige']);

    // Codes de remise (livraison/retrait). Motif obligatoire, double
    // journalisation : ce n'est ni au vendeur ni au livreur que ce code
    // est destiné, seulement à l'admin pour un client injoignable ou un
    // litige — voir le commentaire au-dessus des méthodes.
    Route::get('/sous-commandes/{sousCommande}/code-remise', [AdminController::class, 'codeRemise']);
    Route::post('/sous-commandes/{sousCommande}/code-remise/renvoyer', [AdminController::class, 'renvoyerCodeRemise']);

    // Modération du catalogue. CE CHAÎNON MANQUAIT : un produit créé par
    // un vendeur naît « en_attente » et rien ne permettait de le publier,
    // donc aucune fiche nouvelle ne pouvait jamais être vendue.
    Route::get('/produits', [AdminController::class, 'produits']);
    Route::post('/produits/{produit}/moderer', [AdminController::class, 'modererProduit']);

    // Reversements : LECTURE SEULE. Déclarer un virement « payé » depuis
    // une console reviendrait à affirmer qu'une somme est partie ; cette
    // confirmation doit venir du prestataire, par webhook.
    Route::get('/reversements', [AdminController::class, 'reversements']);

    Route::post('/lots-qr', [LotQrController::class, 'creer']);
    Route::get('/lots-qr/{lot}/planche', [LotQrController::class, 'planche'])->name('admin.lots.planche');
    Route::get('/lots-qr/{lot}/statistiques', [LotQrController::class, 'statistiques']);
    Route::post('/lots-qr/{lot}/rappel', [LotQrController::class, 'rappeler']);
});

// ---------------------------------------------------------------------
// 4. WEBHOOKS PSP
// ---------------------------------------------------------------------
// Hors auth:sanctum par nature : c'est le prestataire de paiement qui
// appelle, pas un utilisateur de la plateforme. L'authenticité est
// vérifiée par signature (PaymentGatewayInterface::verifierSignatureWebhook()),
// pas par un jeton Sanctum.
Route::post('/webhooks/paiement/{prestataire}', [PaiementWebhookController::class, 'recevoir']);

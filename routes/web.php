<?php

use App\Http\Controllers\Api\VerificationQrController;
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

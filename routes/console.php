<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tâches planifiées
|--------------------------------------------------------------------------
| Quatre tâches gouvernent l'argent d'Afrishop. Trois tournent seules ;
| la quatrième a besoin d'un chiffre qu'aucune API ne fournit encore.
*/

// LA PLUS IMPORTANTE DU SYSTÈME : sans elle, l'argent d'un vendeur reste
// bloqué dès qu'un client oublie de confirmer réception — c'est-à-dire
// presque toujours. Si elle cesse de tourner, personne ne le remarque
// avant les réclamations des vendeurs.
Schedule::command('afrishop:liberer-fonds')->dailyAt('06:00');

// Regroupe les ventes libérées en un reversement par boutique.
Schedule::command('afrishop:preparer-reversements')->weeklyOn(1, '07:00');

// Calcule le volet local de la réconciliation PSP du jour précédent.
// Le volet côté prestataire reste à rapprocher manuellement tant
// qu'aucun connecteur PSP n'est branché (ReconciliationPspService::rapprocher()).
Schedule::command('afrishop:reconcilier-psp')->dailyAt('05:30');

// afrishop:arreter-cantonnement N'EST PAS planifiée : le solde du compte
// de cantonnement est un chiffre humain (relevé bancaire), pas une
// valeur que l'application peut calculer seule. À lancer par un
// opérateur, ou à brancher sur un futur connecteur bancaire.
//   php artisan afrishop:arreter-cantonnement BF 45230000

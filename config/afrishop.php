<?php

/**
 * ---------------------------------------------------------------------
 * Paramètres métier Afrishop
 * ---------------------------------------------------------------------
 * Tout ce qui est susceptible d'être renégocié, ajusté ou débattu vit
 * ici plutôt que dans le code. Changer une règle commerciale ne doit
 * jamais demander de modifier une classe.
 */
return [

    /*
     * Délai de confirmation automatique.
     * Une sous-commande marquée « livrée » dont le client ne dit rien
     * pendant ce nombre de jours est réputée acceptée : les fonds sont
     * débloqués vers la boutique.
     *
     * Trop court, le client n'a pas le temps d'ouvrir son colis.
     * Trop long, le vendeur attend son argent et se décourage.
     * 3 jours est le compromis retenu au démarrage.
     */
    'delai_confirmation_auto' => (int) env('AFRISHOP_DELAI_CONFIRMATION_AUTO', 3),

    /* Commission Afrishop par défaut, en pourcentage, à la création d'une boutique. */
    'commission_defaut' => (int) env('AFRISHOP_COMMISSION_DEFAUT', 10),

    /*
     * Prestataire de paiement en ligne.
     * « fake » est le seul driver livré : il simule un encaissement
     * instantané, pour que le parcours de commande en ligne soit
     * testable sans contrat signé avec un PSP. Brancher un vrai
     * prestataire consiste à ajouter une classe qui implémente
     * PaymentGatewayInterface et un cas dans AppServiceProvider —
     * rien d'autre dans le code n'a besoin de changer.
     */
    'psp' => [
        'driver'         => env('PSP_DRIVER', 'fake'),
        'webhook_secret' => env('PSP_WEBHOOK_SECRET'),
    ],

    /*
     * Seuil minimum de reversement.
     * En dessous, on attend le cycle suivant : les frais fixes de
     * transfert Mobile Money rendraient l'opération absurde.
     */
    'reversement_minimum_cfa' => 5000,

    /* Réputation : nombre de commandes traitées avant d'afficher une note. */
    'reputation_seuil_commandes' => 3,

    'qr' => [
        /* Longueur du jeton aléatoire encodé dans le QR code.
         * 12 caractères sur un alphabet de 32 → 32^12 ≈ 1,2 × 10^18.
         * Un attaquant testant 1000 jetons/seconde mettrait 38 millions
         * d'années à en trouver un valide. */
        'longueur_jeton' => 12,

        /* Alphabet sans caractères ambigus (ni 0/O, ni 1/I/L) : le code
         * peut être relu et saisi à la main par un client. */
        'alphabet_jeton' => '23456789ABCDEFGHJKMNPQRSTUVWXYZ',

        /* Nombre de chiffres du numéro séquentiel : 4 → « 0026 ». */
        'largeur_numero_defaut' => 4,

        /* Quantité maximale d'étiquettes générables en une fois. */
        'quantite_max_par_lot' => 10000,

        /* Au-delà de ce nombre de scans, un code est considéré suspect
         * (étiquette probablement photocopiée) et signalé à l'admin. */
        'seuil_scans_suspects' => 50,

        'url_verification' => env('AFRISHOP_URL_VERIFICATION', 'https://afrishop.bf/v'),
        'sel_scan' => env('AFRISHOP_SEL_SCAN', ''),
    ],
];

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

        /*
         * CinetPay — Établissement de Paiement agréé BCEAO.
         *
         * Les trois secrets viennent du tableau de bord marchand. Ils ne
         * doivent JAMAIS être écrits ici en dur : `.env` n'est pas
         * versionné, ce fichier l'est.
         *
         * `secret_key` sert uniquement à vérifier le HMAC des webhooks.
         * Sans elle, aucune notification n'est acceptée — et donc aucun
         * paiement n'est jamais confirmé. C'est la panne silencieuse la
         * plus probable de cette intégration.
         *
         * `canaux` : ALL, MOBILE_MONEY, CREDIT_CARD ou WALLET.
         * « ALL » laisse le client choisir sur la page CinetPay ; c'est
         * ce qui permet de servir un acheteur hors zone, à la carte,
         * sans intégration supplémentaire.
         */
        'cinetpay' => [
            'api_key'    => env('CINETPAY_API_KEY'),
            'site_id'    => env('CINETPAY_SITE_ID'),
            'secret_key' => env('CINETPAY_SECRET_KEY'),
            'canaux'     => env('CINETPAY_CANAUX', 'ALL'),
        ],

        /*
         * PayDunya — Établissement de Paiement agréé BCEAO, lui aussi.
         *
         * SON INTÉRÊT PARTICULIER : un vrai bac à sable. `mode = test`
         * donne des clés préfixées `test_` et une page de paiement
         * fictive. Toute la chaîne se vérifie donc AVANT tout
         * engagement commercial, et le passage en production ne change
         * qu'une variable d'environnement.
         *
         * `mode` n'est pas décoratif : la passerelle refuse une
         * notification dont le mode ne correspond pas. Sans ce contrôle,
         * une notification de test reçue par l'installation de
         * production créditerait une vente que personne n'a payée.
         */
        'paydunya' => [
            'mode'         => env('PAYDUNYA_MODE', 'test'),   // test | live
            'master_key'   => env('PAYDUNYA_MASTER_KEY'),
            'private_key'  => env('PAYDUNYA_PRIVATE_KEY'),
            'token'        => env('PAYDUNYA_TOKEN'),
            /* Le nom affiché sur la page de paiement et sur le reçu :
             * c'est ce que le client lit au moment de valider. Un nom
             * qu'il ne reconnaît pas est une raison d'abandonner. */
            'nom_boutique' => env('PAYDUNYA_NOM_BOUTIQUE', 'Afrishop'),
        ],
    ],

    /*
     * Passerelle SMS.
     * Le SMS porte le code de livraison à usage unique : sans envoi,
     * le parcours de livraison ne peut pas se terminer.
     *
     * « journal » n'envoie rien et écrit dans les logs — c'est le
     * pendant SMS de « fake » côté PSP, pour développer sans contrat
     * opérateur. « orange » utilise l'API SMS Burkina Faso d'Orange.
     *
     * Le coût unitaire est renseigné à la main d'après le paquet acheté
     * (Silver : 1 000 SMS pour 8 000 F → 8 F). Il sert à chiffrer la
     * ligne « SMS » chaque mois, pas à facturer : le vrai décompte reste
     * celui d'Orange.
     */
    'sms' => [
        'driver'            => env('SMS_DRIVER', 'journal'),
        'client_id'         => env('SMS_ORANGE_CLIENT_ID'),
        'client_secret'     => env('SMS_ORANGE_CLIENT_SECRET'),
        'adresse_expediteur'=> env('SMS_ORANGE_ADRESSE', '+22600000000'),
        /* 11 caractères alphanumériques maximum, validés par Orange. */
        'nom_expediteur'    => env('SMS_NOM_EXPEDITEUR', 'AFRISHOP'),
        'cout_unitaire_cfa' => (int) env('SMS_COUT_UNITAIRE_CFA', 8),
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

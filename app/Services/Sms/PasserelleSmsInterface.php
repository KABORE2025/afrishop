<?php

namespace App\Services\Sms;

/**
 * =====================================================================
 *  CONTRAT D'UNE PASSERELLE SMS
 * =====================================================================
 *  Même principe que PaymentGatewayInterface : le reste de
 *  l'application ne connaît que ce contrat. Changer d'opérateur ou
 *  d'agrégateur consiste à écrire une classe qui l'implémente et à
 *  l'ajouter au match() de AppServiceProvider.
 *
 *  POURQUOI CETTE COUCHE EXISTE
 *  Le SMS n'est pas un détail d'intendance ici : c'est lui qui porte
 *  le code de livraison à usage unique. Sans envoi, le client ne
 *  reçoit pas son code, le livreur ne peut pas le faire valider, et le
 *  parcours de livraison s'arrête. Le circuit de l'argent en dépend
 *  directement.
 *
 *  ET C'EST AUSSI UN COÛT
 *  À 8 F le SMS chez Orange Burkina et environ 133 F chez un
 *  agrégateur international, le choix du fournisseur change le modèle
 *  économique, pas seulement la facture technique. D'où `coutUnitaireCfa()` :
 *  chaque envoi est chiffré et journalisé, pour que la ligne « SMS »
 *  soit lisible chaque mois plutôt que découverte en fin d'année.
 * =====================================================================
 */
interface PasserelleSmsInterface
{
    /**
     * Envoie un message à un numéro.
     *
     * Ne lève pas d'exception sur un échec métier (numéro invalide,
     * solde épuisé, refus opérateur) : un échec d'envoi est une donnée
     * à journaliser, pas un incident applicatif. Seule une panne
     * réseau réelle peut remonter.
     *
     * @param  string $telephone Numéro au format international (+22670000000)
     * @return array{statut: 'envoye'|'echoue', reference_externe: ?string, erreur: ?string}
     */
    public function envoyer(string $telephone, string $message): array;

    /** Nom du fournisseur, écrit tel quel dans `notifications.fournisseur`. */
    public function nom(): string;

    /**
     * Coût d'UN segment de SMS, en francs CFA.
     * Un message de plus de 160 caractères compte pour deux segments —
     * c'est NotificationService qui fait ce calcul, pas la passerelle.
     */
    public function coutUnitaireCfa(): int;
}

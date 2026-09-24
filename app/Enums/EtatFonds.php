<?php

namespace App\Enums;

/**
 * État de l'argent d'une sous-commande — délibérément SÉPARÉ du statut
 * logistique. Un colis livré avec des fonds encore séquestrés est le
 * cas normal pendant trois jours : c'est la fenêtre de protection de
 * l'acheteur, et c'est ce qui distingue Afrishop d'un annuaire.
 */
enum EtatFonds: string
{
    /**
     * État de naissance de toute sous-commande : le client a été envoyé
     * vers le prestataire, l'encaissement n'est pas encore confirmé.
     * Rien à séquestrer, et RIEN NE DOIT ÊTRE EXPÉDIÉ dans cet état.
     */
    case AttenteEncaissement = 'attente_encaissement';
    /** Encaissé, cantonné, attribué à la boutique, pas encore viré. */
    case Sequestre = 'sequestre';
    case Reverse   = 'reverse';
    case Rembourse = 'rembourse';
    /** Commande annulée sans que le paiement ait abouti : personne n'a été payé. */
    case Impaye    = 'impaye';

    public function libelle(): string
    {
        return match ($this) {
            self::AttenteEncaissement => 'Paiement en attente',
            self::Sequestre           => 'Fonds en séquestre',
            self::Reverse             => 'Reversé à la boutique',
            self::Rembourse           => 'Remboursé au client',
            self::Impaye              => 'Non payée — commande annulée',
        };
    }

    public function estDefinitif(): bool
    {
        return in_array($this, [self::Reverse, self::Rembourse, self::Impaye], true);
    }
}

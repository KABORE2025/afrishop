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
    /** Paiement à la livraison : rien n'est encaissé, rien à séquestrer. */
    case AttenteEncaissement = 'attente_encaissement';
    /** Encaissé, cantonné, attribué à la boutique, pas encore viré. */
    case Sequestre = 'sequestre';
    case Reverse   = 'reverse';
    case Rembourse = 'rembourse';
    /** Colis refusé à la porte : personne n'a été payé. */
    case Impaye    = 'impaye';

    public function libelle(): string
    {
        return match ($this) {
            self::AttenteEncaissement => 'En attente d\'encaissement',
            self::Sequestre           => 'Fonds en séquestre',
            self::Reverse             => 'Reversé à la boutique',
            self::Rembourse           => 'Remboursé au client',
            self::Impaye              => 'Impayé — colis refusé',
        };
    }

    public function estDefinitif(): bool
    {
        return in_array($this, [self::Reverse, self::Rembourse, self::Impaye], true);
    }
}

<?php

namespace App\Enums;

enum ModePaiement: string
{
    case MobileMoney      = 'mobile_money';
    case Carte            = 'carte';
    case Virement         = 'virement';
    /** Le mode dominant du marché ouest-africain. */
    case EspecesLivraison = 'especes_livraison';

    /** Y a-t-il un encaissement AVANT la livraison ? */
    public function encaissePrealablement(): bool
    {
        return $this !== self::EspecesLivraison;
    }

    /** Les plafonds de monnaie électronique BCEAO s'appliquent-ils ? */
    public function soumisAuPlafond(): bool
    {
        return $this === self::MobileMoney;
    }

    public function libelle(): string
    {
        return match ($this) {
            self::MobileMoney      => 'Mobile Money',
            self::Carte            => 'Carte bancaire',
            self::Virement         => 'Virement',
            self::EspecesLivraison => 'Espèces à la livraison',
        };
    }
}

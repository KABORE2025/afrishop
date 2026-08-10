<?php

namespace App\Enums;

/**
 * Régime fiscal d'une boutique. Détermine trois choses à la fois :
 * la TVA sur ses ventes, sa capacité à émettre une facture certifiée,
 * et le taux de retenue à la source qu'elle subit.
 */
enum RegimeFiscal: string
{
    /**
     * Aucun numéro fiscal. C'est le cas le plus fréquent chez les
     * artisans — et le plus coûteux pour eux : 25 % de retenue au
     * Burkina, contre 5 % avec un IFU.
     */
    case NonImmatricule = 'non_immatricule';

    /** CME (BF), CGU (SN), TPU (TG), Entreprenant (CI), impôt synthétique (ML, NE). */
    case Forfaitaire = 'forfaitaire';
    case Simplifie   = 'simplifie';
    case Reel        = 'reel';

    /** Seul le régime réel collecte la TVA au-dessus des seuils. */
    public function peutCollecterTva(): bool
    {
        return $this === self::Reel;
    }

    public function libelle(): string
    {
        return match ($this) {
            self::NonImmatricule => 'Non immatriculé',
            self::Forfaitaire    => 'Régime forfaitaire',
            self::Simplifie      => 'Réel simplifié',
            self::Reel           => 'Réel normal',
        };
    }
}

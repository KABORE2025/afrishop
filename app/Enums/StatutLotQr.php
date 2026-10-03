<?php

namespace App\Enums;

/**
 * Cycle de vie d'un lot d'étiquettes QR. Les valeurs sont celles de la
 * colonne `lots_qr.statut`.
 *
 * Ce fichier MANQUAIT alors que LotQr, LotQrService et LotQrController
 * l'utilisaient : la toute première création de lot plantait.
 */
enum StatutLotQr: string
{
    /** Le vendeur a demandé des étiquettes ; aucune n'est encore générée. */
    case Demande       = 'demande';
    case Refuse        = 'refuse';
    /** Étiquettes générées, planche pas encore téléchargée. */
    case Genere        = 'genere';
    /** Planche téléchargée : les étiquettes existent physiquement. */
    case Imprime       = 'imprime';
    /** Au moins une étiquette a été scannée par un client. */
    case EnCirculation = 'en_circulation';
    /** Lot retiré (contrefaçon, défaut, péremption) : tout scan alerte. */
    case Rappele       = 'rappele';

    public function libelle(): string
    {
        return match ($this) {
            self::Demande       => 'Demandé par la boutique',
            self::Refuse        => 'Demande refusée',
            self::Genere        => 'Généré, à imprimer',
            self::Imprime       => 'Imprimé',
            self::EnCirculation => 'En circulation',
            self::Rappele       => 'Rappelé',
        };
    }
}

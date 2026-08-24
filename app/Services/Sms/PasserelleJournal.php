<?php

namespace App\Services\Sms;

use Illuminate\Support\Str;

/**
 * =====================================================================
 *  PASSERELLE DE DÉVELOPPEMENT — écrit dans les logs, n'envoie rien
 * =====================================================================
 *  Équivalent SMS de FakeGateway : permet de dérouler tout le parcours
 *  de livraison, code à usage unique compris, sans contrat opérateur et
 *  sans dépenser un franc.
 *
 *  Le message complet est écrit dans le log, code de livraison inclus.
 *  C'est VOULU en développement — c'est comme cela qu'on teste — et
 *  c'est exactement pour cette raison que ce driver ne doit JAMAIS être
 *  actif en production : le log deviendrait un annuaire de codes de
 *  livraison valides. La garde ci-dessous le rappelle bruyamment.
 * =====================================================================
 */
class PasserelleJournal implements PasserelleSmsInterface
{
    public function envoyer(string $telephone, string $message): array
    {
        if (app()->environment('production')) {
            logger()->critical(
                'SMS_DRIVER=journal en PRODUCTION : aucun SMS ne part réellement. '
                . 'Les clients ne reçoivent pas leur code de livraison.'
            );
        }

        logger()->info('[SMS simulé] → ' . $telephone . ' : ' . $message);

        return [
            'statut'            => 'envoye',
            'reference_externe' => 'journal-' . Str::lower(Str::random(12)),
            'erreur'            => null,
        ];
    }

    public function nom(): string { return 'journal'; }

    /* Un envoi simulé ne coûte rien : ne pas fausser les statistiques. */
    public function coutUnitaireCfa(): int { return 0; }
}

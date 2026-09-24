<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Photos d'un litige, servies depuis le disque PRIVÉ.
 *
 * Aucune session ici (le projet n'en a pas) : c'est la signature de
 * l'URL, vérifiée par le middleware `signed`, qui fait la garde. Seuls
 * l'API admin et l'API vendeur de la boutique concernée émettent ces
 * liens (LitigeService::urlsPhotos()), et ils expirent au bout de 30 min.
 */
class PhotoLitigeController extends Controller
{
    private const COLONNES = ['vignette' => 'chemin_vignette', 'grand' => 'chemin_grand'];

    public function __invoke(Media $media, string $taille): Response
    {
        abort_unless($media->proprietaire_type === 'litige' && isset(self::COLONNES[$taille]), 404);

        // Sans GD, les tailles réduites n'existent pas : on sert l'original.
        $chemin = $media->{self::COLONNES[$taille]} ?? $media->chemin_original;
        $disque = Storage::disk('local');

        abort_unless($chemin && $disque->exists($chemin), 404);

        return $disque->response($chemin, headers: [
            // Jamais en cache partagé (proxy d'opérateur, cybercafé).
            'Cache-Control' => 'private, max-age=1800',
        ]);
    }
}

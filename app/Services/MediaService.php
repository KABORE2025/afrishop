<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * =====================================================================
 *  MÉDIAS PRODUIT — images et vidéos
 * =====================================================================
 *  SUR UN MARCHÉ OÙ LA PHOTO FAIT LA VENTE ET OÙ LA BANDE PASSANTE SE
 *  PAIE AU MÉGAOCTET, REDIMENSIONNER N'EST PAS DE LA COQUETTERIE.
 *
 *  Un téléphone produit couramment une photo de 4 Mo. Servie telle
 *  quelle dans une grille de quarante produits, elle coûte 160 Mo à
 *  l'acheteur — soit, à 500 F le gigaoctet, une centaine de francs pour
 *  regarder un catalogue. La même image en 400 px pèse une trentaine de
 *  kilo-octets. C'est un facteur cent, et il décide si le catalogue est
 *  consultable ou non.
 *
 *  D'où les trois tailles déjà prévues par la table :
 *    1200 px — fiche produit
 *     400 px — grille de la vitrine
 *     100 px — panier et listes
 *
 *  PAS DE DÉPENDANCE NOUVELLE. Le redimensionnement utilise GD, livré
 *  avec la plupart des installations PHP. Si GD manque, l'original est
 *  conservé et l'incident est journalisé plutôt que de faire échouer
 *  l'envoi : un vendeur qui ne peut pas publier sa photo abandonne, un
 *  vendeur dont la photo est lourde vend quand même.
 *
 *  LA VIDÉO N'EST PAS TRANSCODÉE. Transcoder demanderait ffmpeg sur le
 *  serveur ; le fichier est donc stocké tel qu'il arrive, et c'est le
 *  plafond de taille qui tient lieu de garde-fou. Un poster est
 *  obligatoire : sans lui, la vidéo se chargerait pour être vue.
 * =====================================================================
 */
class MediaService
{
    /** Tailles dérivées, en pixels sur le plus grand côté. */
    private const TAILLES = [
        'chemin_grand'     => 1200,
        'chemin_vignette'  => 400,
        'chemin_miniature' => 100,
    ];

    private const QUALITE_JPEG = 82;

    public function __construct(private string $disque = 'public') {}

    /**
     * Ajoute une image à un produit.
     *
     * @param  string $type Type de propriétaire : « produit », « boutique »…
     */
    public function ajouterImage(UploadedFile $fichier, string $type, int $proprietaireId,
                                 ?string $texteAlternatif = null): Media
    {
        $dossier = "{$type}s/{$proprietaireId}";
        $base    = Str::uuid()->toString();

        $cheminOriginal = $fichier->storeAs($dossier, "{$base}-original." . $fichier->extension(), $this->disque);

        $chemins = [];
        [$largeur, $hauteur] = $this->dimensions($fichier->getRealPath());

        foreach (self::TAILLES as $colonne => $cote) {
            $chemins[$colonne] = $this->reduire(
                $fichier->getRealPath(), $dossier, "{$base}-{$cote}", $cote
            );
        }

        return Media::create(array_merge([
            'proprietaire_type' => $type,
            'proprietaire_id'   => $proprietaireId,
            'type'              => 'image',
            'mime'              => $fichier->getMimeType(),
            'chemin_original'   => $cheminOriginal,
            'largeur'           => $largeur,
            'hauteur'           => $hauteur,
            'poids_octets'      => $fichier->getSize(),
            'texte_alternatif'  => $texteAlternatif,
            'ordre'             => $this->prochainOrdre($type, $proprietaireId),
        ], $chemins));
    }

    /**
     * Ajoute une vidéo. Le poster est OBLIGATOIRE côté appelant : sans
     * lui, la seule façon de savoir ce que contient la vidéo serait de
     * la télécharger.
     */
    public function ajouterVideo(UploadedFile $video, UploadedFile $poster,
                                 string $type, int $proprietaireId,
                                 ?string $texteAlternatif = null): Media
    {
        $dossier = "{$type}s/{$proprietaireId}";
        $base    = Str::uuid()->toString();

        $cheminVideo = $video->storeAs($dossier, "{$base}-video." . $video->extension(), $this->disque);

        // Le poster passe par la même réduction que les images : il sera
        // affiché dans la grille, exactement comme une photo.
        $cheminPoster = $this->reduire($poster->getRealPath(), $dossier, "{$base}-poster", 1200);
        $vignette     = $this->reduire($poster->getRealPath(), $dossier, "{$base}-400", 400);
        $miniature    = $this->reduire($poster->getRealPath(), $dossier, "{$base}-100", 100);

        return Media::create([
            'proprietaire_type' => $type,
            'proprietaire_id'   => $proprietaireId,
            'type'              => 'video',
            'mime'              => $video->getMimeType(),
            'chemin_original'   => $cheminVideo,
            'chemin_poster'     => $cheminPoster,
            'chemin_grand'      => $cheminPoster,
            'chemin_vignette'   => $vignette,
            'chemin_miniature'  => $miniature,
            'poids_octets'      => $video->getSize(),
            'texte_alternatif'  => $texteAlternatif,
            'ordre'             => $this->prochainOrdre($type, $proprietaireId),
        ]);
    }

    /**
     * Supprime le média ET ses fichiers.
     *
     * Laisser les fichiers derrière soi remplit le disque en silence :
     * personne ne remarque un dossier qui grossit, jusqu'au jour où le
     * serveur refuse d'écrire.
     */
    public function supprimer(Media $media): void
    {
        foreach (['chemin_original', 'chemin_poster', 'chemin_grand',
                  'chemin_vignette', 'chemin_miniature'] as $colonne) {
            $chemin = $media->$colonne;
            if ($chemin && Storage::disk($this->disque)->exists($chemin)) {
                Storage::disk($this->disque)->delete($chemin);
            }
        }

        $media->delete();
    }

    // -----------------------------------------------------------------

    /**
     * Réduit une image sur son plus grand côté et l'écrit en JPEG.
     * Renvoie le chemin relatif, ou null si GD n'est pas disponible.
     */
    private function reduire(string $source, string $dossier, string $nom, int $cote): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            logger()->warning(
                "GD absent : impossible de réduire les images. Les originaux sont conservés, "
                . "ce qui rendra le catalogue très lourd à charger. Installer l'extension php-gd."
            );
            return null;
        }

        $info = @getimagesize($source);
        if (! $info) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            IMAGETYPE_GIF  => @imagecreatefromgif($source),
            default        => null,
        };

        if (! $image) {
            return null;
        }

        $image = $this->redresser($image, $source, $info[2]);

        $l = imagesx($image);
        $h = imagesy($image);

        // On ne SUR-dimensionne jamais : agrandir une petite photo la
        // rend floue et alourdit le fichier pour rien.
        $ratio    = min(1, $cote / max($l, $h));
        $nouvelleL = max(1, (int) round($l * $ratio));
        $nouvelleH = max(1, (int) round($h * $ratio));

        $sortie = imagecreatetruecolor($nouvelleL, $nouvelleH);

        // Fond blanc : un PNG transparent aplati en JPEG donnerait un
        // fond noir, ce qui est spectaculairement laid sur un catalogue.
        imagefill($sortie, 0, 0, imagecolorallocate($sortie, 255, 255, 255));
        imagecopyresampled($sortie, $image, 0, 0, 0, 0, $nouvelleL, $nouvelleH, $l, $h);

        $chemin = "{$dossier}/{$nom}.jpg";
        $absolu = Storage::disk($this->disque)->path($chemin);

        if (! is_dir(dirname($absolu))) {
            mkdir(dirname($absolu), 0755, true);
        }

        imagejpeg($sortie, $absolu, self::QUALITE_JPEG);

        imagedestroy($image);
        imagedestroy($sortie);

        return $chemin;
    }

    /**
     * Remet la photo à l'endroit d'après l'EXIF.
     *
     * Les téléphones n'écrivent pas la photo tournée : ils la marquent
     * « à tourner à l'affichage ». GD ignore cette marque. Sans ce
     * redressement, une photo prise à la verticale s'affiche couchée —
     * et le vendeur croit que la plateforme a abîmé son image.
     */
    private function redresser($image, string $source, int $typeImage)
    {
        if ($typeImage !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($source);
        $orientation = $exif['Orientation'] ?? 1;

        return match ($orientation) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function dimensions(string $chemin): array
    {
        $info = @getimagesize($chemin);

        return [$info[0] ?? null, $info[1] ?? null];
    }

    /** Le nouveau média se range en dernier. */
    private function prochainOrdre(string $type, int $proprietaireId): int
    {
        return (int) Media::where('proprietaire_type', $type)
            ->where('proprietaire_id', $proprietaireId)
            ->max('ordre') + 1;
    }
}

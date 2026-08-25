<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 *  ENVOI D'UN MÉDIA PRODUIT
 * =====================================================================
 *  LES PLAFONDS SONT LE CŒUR DE CE FICHIER, PAS UN DÉTAIL.
 *
 *  Image : 8 Mo. Large pour une photo de téléphone, et sans conséquence
 *  puisqu'elle est réduite à l'arrivée — c'est l'original qu'on limite,
 *  pas ce qui sera servi.
 *
 *  Vidéo : 20 Mo, soit une trentaine de secondes filmées au téléphone.
 *  Ce plafond est bas, et c'est voulu. Une vidéo n'est pas transcodée :
 *  elle est servie telle quelle. À 60 Mo, un seul visionnage coûterait
 *  une trentaine de francs de données à l'acheteur, et autant en bande
 *  passante à la plateforme. Mieux vaut refuser un fichier trop lourd
 *  que de facturer silencieusement le client.
 *
 *  ⚠ CES PLAFONDS NE SUFFISENT PAS SEULS. PHP refuse par défaut tout
 *  envoi au-delà de `upload_max_filesize` (2 Mo) et `post_max_size`
 *  (8 Mo). Au-delà, la requête n'arrive même pas jusqu'à cette
 *  validation : Laravel reçoit un corps vide et l'erreur est
 *  incompréhensible. Il faut relever les deux dans `php.ini`.
 * =====================================================================
 */
class AjouterMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->estVendeur() ?? false;
    }

    public function rules(): array
    {
        $estVideo = $this->input('type') === 'video';

        return [
            'type' => ['required', 'in:image,video'],

            'fichier' => $estVideo
                ? ['required', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:20480']
                : ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],

            /* Poster OBLIGATOIRE pour une vidéo. Sans lui, la seule
             * façon de savoir ce qu'elle contient serait de la
             * télécharger — exactement ce qu'on veut éviter. */
            'poster' => $estVideo
                ? ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192']
                : ['nullable'],

            'texte_alternatif' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'fichier.max'    => 'Fichier trop lourd. Photo : 8 Mo maximum. Vidéo : 20 Mo, soit environ 30 secondes.',
            'fichier.mimes'  => 'Formats acceptés pour une photo : JPEG, PNG ou WebP.',
            'fichier.mimetypes' => 'Formats acceptés pour une vidéo : MP4, WebM ou MOV.',
            'poster.required'=> 'Une vidéo demande une image de couverture : sans elle, le client devrait '
                              . 'télécharger la vidéo pour savoir ce qu\'elle montre.',
        ];
    }
}

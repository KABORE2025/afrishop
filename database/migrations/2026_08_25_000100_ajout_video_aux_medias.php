<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 *  La table `medias` accepte désormais la VIDÉO
 * =====================================================================
 *  Elle n'avait été pensée que pour l'image : trois chemins dérivés
 *  (1200 / 400 / 100 px) et rien pour dire ce qu'est le fichier. Une
 *  vidéo rangée dans `chemin_original` sans le signaler produirait une
 *  balise <img> pointant sur un MP4 — un carré vide, sans erreur.
 *
 *  QUATRE COLONNES, ET CHACUNE SERT À UNE DÉCISION D'AFFICHAGE :
 *
 *   · `type`          — image ou vidéo. Décide de la balise.
 *   · `mime`          — le lecteur en a besoin (<source type="…">).
 *   · `duree_s`       — annoncée AVANT lecture, pour que le client sache
 *                       ce qu'il va télécharger.
 *   · `chemin_poster` — l'image fixe montrée à la place de la vidéo tant
 *                       qu'on ne clique pas. C'est ELLE qui rend la
 *                       vidéo acceptable sur un réseau facturé au
 *                       mégaoctet : rien ne se charge sans un geste
 *                       explicite de l'acheteur.
 * =====================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medias', function (Blueprint $t) {
            $t->enum('type', ['image', 'video'])->default('image')->after('proprietaire_id');
            $t->string('mime', 60)->nullable()->after('type');
            $t->unsignedSmallInteger('duree_s')->nullable()->after('poids_octets')
              ->comment('Vidéo seulement — annoncée avant lecture');
            $t->string('chemin_poster')->nullable()->after('chemin_original')
              ->comment('Vignette fixe de la vidéo : rien ne se charge sans clic');
        });
    }

    public function down(): void
    {
        Schema::table('medias', function (Blueprint $t) {
            $t->dropColumn(['type', 'mime', 'duree_s', 'chemin_poster']);
        });
    }
};

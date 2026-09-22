<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrait en boutique : même principe de preuve que la livraison à
 * domicile (code à usage unique envoyé au client), mais sans passer par
 * une Expedition — il n'y a ni transporteur ni livreur, juste le vendeur
 * qui remet la marchandise à son propre comptoir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sous_commandes', function (Blueprint $t) {
            $t->char('code_retrait', 6)->nullable()->after('etat_fonds');
            $t->timestamp('code_retrait_envoye_le')->nullable()->after('code_retrait');
            $t->unsignedTinyInteger('retrait_tentatives')->default(0)->after('code_retrait_envoye_le');
        });
    }

    public function down(): void
    {
        Schema::table('sous_commandes', function (Blueprint $t) {
            $t->dropColumn(['code_retrait', 'code_retrait_envoye_le', 'retrait_tentatives']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilisateurs', function (Blueprint $t) {
            // L'empreinte permet de retrouver un agent sans exposer sa CNIB.
            $t->char('cnib_hash', 64)->nullable()->unique()->after('email');
            $t->text('cnib_chiffree')->nullable()->after('cnib_hash');
        });

        Schema::create('agent_remise_telephones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('livreur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $t->string('telephone', 20)->unique();
            $t->boolean('est_principal')->default(false);
            $t->timestamp('verifie_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->unique(['livreur_id', 'telephone']);
        });

        Schema::create('agents_remise_boutiques', function (Blueprint $t) {
            $t->id();
            $t->foreignId('livreur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $t->foreignId('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->enum('statut', ['en_attente', 'actif', 'refuse', 'suspendu'])->default('en_attente');
            $t->boolean('autorise_livraison')->default(true);
            $t->boolean('autorise_retrait_boutique')->default(true);
            $t->foreignId('invite_par_utilisateur_id')->constrained('utilisateurs')->restrictOnDelete();
            $t->char('jeton_invitation_hash', 64)->nullable();
            $t->timestamp('invitation_expire_le')->nullable();
            $t->timestamp('accepte_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('modifie_le')->useCurrent()->useCurrentOnUpdate();
            $t->unique(['livreur_id', 'boutique_id']);
            $t->index(['boutique_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents_remise_boutiques');
        Schema::dropIfExists('agent_remise_telephones');
        Schema::table('utilisateurs', function (Blueprint $t) {
            $t->dropUnique(['cnib_hash']);
            $t->dropColumn(['cnib_hash', 'cnib_chiffree']);
        });
    }
};

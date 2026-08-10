<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 6/8 — Fiscalité et facturation
 *
 * Deux factures distinctes sur une même vente, à ne pas confondre :
 *  · la BOUTIQUE facture le CLIENT (marchandise) ;
 *  · la PLATEFORME facture la BOUTIQUE (commission de service).
 *
 * Difficulté propre à la zone : au Bénin, au Burkina, en Côte d'Ivoire
 * et au Niger, une facture n'a valeur probante que si elle sort d'un
 * dispositif certifié par l'État, rattaché au numéro fiscal de
 * l'émetteur. Une plateforme ne peut donc PAS auto-facturer librement
 * pour un vendeur non enrôlé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $t) {
            $t->id();
            $t->enum('type', ['vente_client', 'commission_boutique', 'avoir']);
            $t->unsignedTinyInteger('pays_id');
            $t->string('numero', 40)->comment('Séquence continue et sans trou, par pays et par type');

            // Émetteur et destinataire en clair : une facture doit
            // rester lisible même si la boutique ferme ou si le client
            // exerce son droit à l'effacement.
            $t->enum('emetteur_type', ['boutique', 'plateforme']);
            $t->string('emetteur_nom', 160);
            $t->string('emetteur_numero_fiscal', 40)->nullable();
            $t->string('destinataire_nom', 160);
            $t->string('destinataire_numero_fiscal', 40)->nullable();
            $t->string('destinataire_telephone', 20)->nullable();

            $t->foreignId('sous_commande_id')->nullable()->constrained('sous_commandes')->nullOnDelete();
            $t->foreignId('reversement_id')->nullable()->constrained('reversements')->nullOnDelete();
            $t->unsignedBigInteger('facture_origine_id')->nullable()->comment('Pour un avoir');

            $t->unsignedInteger('montant_ht_cfa')->default(0);
            $t->unsignedInteger('montant_tva_cfa')->default(0);
            $t->decimal('taux_tva_pct', 5, 2)->default(0);
            $t->unsignedInteger('montant_ttc_cfa');

            // Tant que `certifiee_le` est nul dans un pays qui l'exige,
            // la facture n'a aucune valeur probante.
            $t->boolean('certification_requise')->default(false);
            $t->string('certification_ref', 120)->nullable();
            $t->string('certification_qr')->nullable();
            $t->timestamp('certifiee_le')->nullable();
            $t->string('certification_erreur')->nullable();

            $t->string('chemin_pdf')->nullable();
            $t->timestamp('emise_le')->useCurrent();
            $t->unique(['pays_id', 'type', 'numero'], 'uk_factures_numero');
            $t->index('sous_commande_id');
            $t->foreign('pays_id')->references('id')->on('pays')->restrictOnDelete();
        });

        /*
         * Retenues opérées pour le compte du fisc.
         * Cet argent n'appartient à personne dans l'entreprise : il est
         * collecté puis reversé. Le suivre à part évite de le confondre
         * avec du chiffre d'affaires — confusion qui se paie au contrôle.
         */
        Schema::create('retenues_source', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('pays_id');
            $t->foreignId('boutique_id')->constrained('boutiques')->restrictOnDelete();
            $t->foreignId('sous_commande_id')->nullable()->constrained('sous_commandes')->nullOnDelete();
            $t->foreignId('reversement_id')->nullable()->constrained('reversements')->nullOnDelete();
            $t->unsignedInteger('base_cfa');
            $t->decimal('taux_pct', 5, 2);
            $t->unsignedInteger('montant_cfa');
            $t->enum('motif', ['non_immatricule', 'immatricule', 'non_resident']);
            $t->char('periode', 7)->comment('AAAA-MM de déclaration');
            $t->enum('statut', ['a_reverser', 'declaree', 'reversee'])->default('a_reverser');
            $t->string('declaration_ref', 80)->nullable();
            $t->date('reversee_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->index(['pays_id', 'periode', 'statut']);
            $t->index('boutique_id');
            $t->foreign('pays_id')->references('id')->on('pays')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retenues_source');
        Schema::dropIfExists('factures');
    }
};

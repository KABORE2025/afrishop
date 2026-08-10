<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 8/8 — Traçabilité par QR code
 *
 * Principe inchangé depuis la v1, et c'est le seul module qui n'a rien
 * eu à corriger : deux identifiants de nature opposée sur la même
 * étiquette.
 *
 *   code_lisible (02/08/2026/0026) — séquentiel, imprimé, IDENTIFIE
 *   jeton        (K7M2P9QRXW4T)    — aléatoire, caché, AUTHENTIFIE
 *
 * Mettre le numéro séquentiel dans l'URL du QR permettrait de deviner
 * 0027 à partir de 0026, et donc d'imprimer des étiquettes que notre
 * propre site validerait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots_qr', function (Blueprint $t) {
            $t->id();
            $t->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $t->foreignId('variante_id')->nullable()->constrained('variantes_produit')->nullOnDelete()
              ->comment('Une contenance donnée peut avoir son propre lot');
            $t->foreignId('boutique_id')->constrained('boutiques')->restrictOnDelete();
            $t->unsignedTinyInteger('pays_id');
            $t->string('reference', 30)->unique();
            $t->date('date_fabrication');
            $t->date('date_expiration');
            $t->string('fabricant', 160);

            // numero_debut + quantite → étiquettes consécutives.
            // Omettre numero_debut reprend après le lot précédent.
            $t->unsignedInteger('numero_debut');
            $t->unsignedInteger('quantite');
            $t->unsignedTinyInteger('largeur_numero')->default(4)->comment('4 → « 0026 »');
            $t->text('description')->nullable();

            $t->enum('statut', ['demande', 'refuse', 'genere', 'imprime', 'en_circulation', 'rappele'])
              ->default('genere');
            $t->text('motif_refus')->nullable();
            // Un vendeur DEMANDE, un administrateur GÉNÈRE. Laisser un
            // vendeur créer ses propres codes reviendrait à le laisser
            // fabriquer ses propres preuves d'authenticité.
            $t->foreignId('demande_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->foreignId('cree_par_id')->constrained('utilisateurs')->restrictOnDelete();
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('modifie_le')->useCurrent()->useCurrentOnUpdate();
            $t->index('produit_id');
            $t->foreign('pays_id')->references('id')->on('pays')->restrictOnDelete();
        });

        Schema::create('codes_qr', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_qr_id')->constrained('lots_qr')->cascadeOnDelete();
            $t->unsignedInteger('numero');
            $t->string('code_lisible', 30)->unique()->comment('JJ/MM/AAAA/NNNN — IDENTIFIE');
            $t->string('jeton', 16)->unique()->comment('Aléatoire, dans le QR seul — AUTHENTIFIE');
            $t->enum('statut', ['genere', 'imprime', 'active', 'desactive', 'rappele'])->default('genere');
            $t->unsignedInteger('nb_scans')->default(0);
            $t->timestamp('premier_scan_le')->nullable();
            $t->timestamp('dernier_scan_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->unique(['lot_qr_id', 'numero'], 'uk_codes_lot_numero');
        });

        Schema::create('scans_qr', function (Blueprint $t) {
            $t->id();
            $t->foreignId('code_qr_id')->constrained('codes_qr')->cascadeOnDelete();
            $t->timestamp('scanne_le')->useCurrent();
            // Jamais en clair : on compte les lecteurs distincts sans
            // savoir qui ils sont.
            $t->char('ip_hachee', 64)->nullable();
            $t->char('agent_hache', 64)->nullable();
            $t->string('ville_estimee', 80)->nullable();
            $t->char('pays_estime', 2)->nullable();
            $t->index(['code_qr_id', 'scanne_le']);
        });

        // Invariants dont la violation coûterait de l'argent ou
        // détruirait de la confiance : doublés au niveau de la base.
        DB::statement('ALTER TABLE lots_qr ADD CONSTRAINT ck_lots_dates
                       CHECK (date_expiration > date_fabrication)');
        DB::statement('ALTER TABLE lots_qr ADD CONSTRAINT ck_lots_quantite
                       CHECK (quantite >= 1 AND quantite <= 100000)');
        DB::statement('ALTER TABLE avis ADD CONSTRAINT ck_avis_note
                       CHECK (note BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE boutiques ADD CONSTRAINT ck_boutiques_commission
                       CHECK (taux_commission >= 0 AND taux_commission <= 50)');
        DB::statement('ALTER TABLE lignes_commande ADD CONSTRAINT ck_lignes_quantite
                       CHECK (quantite > 0)');
        DB::statement('ALTER TABLE mouvements_compte ADD CONSTRAINT ck_mvt_montant
                       CHECK (montant_cfa > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('scans_qr');
        Schema::dropIfExists('codes_qr');
        Schema::dropIfExists('lots_qr');
    }
};

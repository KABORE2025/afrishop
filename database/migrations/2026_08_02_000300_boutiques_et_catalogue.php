<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3/8 — Boutiques et catalogue
 *
 * Deux règles à ne pas perdre de vue :
 *
 * 1. UNE CATÉGORIE N'EST PAS UNE BOUTIQUE. Le produit porte la
 *    catégorie ; plusieurs boutiques se concurrencent dans la même.
 *
 * 2. LE STOCK EST PORTÉ PAR LA VARIANTE, pas par le produit. Un produit
 *    sans déclinaison a une variante « Standard » : un seul chemin de
 *    code, donc un seul endroit où le stock peut être faux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('parent_id')->nullable();
            $t->string('nom', 80);
            $t->string('slug', 90)->unique();
            $t->string('emoji', 8)->nullable();
            // Le taux réel est résolu par pays et par date : les
            // produits alimentaires ne sont pas taxés pareil partout.
            $t->string('code_taxe', 20)->default('tva_normal');
            $t->smallInteger('ordre')->default(0);
            $t->boolean('active')->default(true);
            $t->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
        });

        Schema::create('boutiques', function (Blueprint $t) {
            $t->id();
            $t->foreignId('utilisateur_id')->constrained('utilisateurs')->restrictOnDelete();
            $t->unsignedTinyInteger('pays_id');
            $t->unsignedInteger('ville_id')->nullable();
            $t->string('code', 12)->unique()->comment('BF-V001 — préfixé par le pays');
            $t->string('nom', 120);
            $t->string('slug', 140)->unique();
            $t->string('emoji', 8)->nullable();
            $t->unsignedBigInteger('logo_media_id')->nullable();
            $t->text('description')->nullable();
            $t->string('telephone', 20);
            $t->decimal('taux_commission', 5, 2)->default(10);
            $t->enum('statut', ['candidature', 'actif', 'suspendu', 'ferme'])->default('candidature');
            $t->enum('niveau', ['nouveau', 'verifie', 'premium'])->default('nouveau');

            /*
             * SITUATION FISCALE — détermine la TVA, la facture et la
             * retenue à la source. L'absence de numéro fiscal coûte
             * 25 % au vendeur burkinabè : c'est le premier sujet
             * d'accompagnement, avant même la photo produit.
             */
            $t->string('numero_fiscal', 40)->nullable()->comment('IFU (BF, BJ), NCC (CI), NINEA (SN)');
            $t->date('numero_fiscal_verifie_le')->nullable();
            $t->enum('regime_fiscal', ['non_immatricule', 'forfaitaire', 'simplifie', 'reel'])
              ->default('non_immatricule');
            $t->boolean('assujetti_tva')->default(false)
              ->comment('La plupart des artisans ne le sont pas : ventes hors champ TVA');
            $t->string('registre_commerce', 40)->nullable();

            $t->unsignedSmallInteger('operateur_paiement_id')->nullable();
            $t->string('paiement_numero', 40)->nullable();
            $t->string('paiement_titulaire', 120)->nullable();
            $t->timestamp('paiement_verifie_le')->nullable()
              ->comment('Vérifié par micro-virement : un numéro mal saisi bloque tous les versements');

            // Congés, deuil, rupture générale. Sans ce mode, des
            // commandes tombent chez un vendeur absent.
            $t->date('fermee_du')->nullable();
            $t->date('fermee_au')->nullable();
            $t->string('message_fermeture')->nullable();

            $t->timestamp('valide_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('modifie_le')->useCurrent()->useCurrentOnUpdate();
            $t->softDeletes('supprime_le');

            $t->index(['pays_id', 'statut']);
            $t->foreign('pays_id')->references('id')->on('pays')->restrictOnDelete();
            $t->foreign('ville_id')->references('id')->on('villes')->nullOnDelete();
            $t->foreign('operateur_paiement_id')->references('id')->on('operateurs_paiement')->nullOnDelete();
        });

        Schema::create('documents_boutique', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->enum('type', ['piece_identite', 'registre_commerce', 'attestation_fiscale',
                              'rib', 'photo_local', 'autre']);
            $t->string('chemin')->comment('Stockage privé : ces fichiers ne sont jamais servis directement');
            $t->enum('statut', ['en_attente', 'valide', 'refuse'])->default('en_attente');
            $t->text('motif_refus')->nullable();
            $t->date('expire_le')->nullable();
            $t->timestamp('depose_le')->useCurrent();
            $t->timestamp('verifie_le')->nullable();
            $t->index(['boutique_id', 'type']);
        });

        Schema::create('candidatures', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('pays_id');
            $t->string('nom_boutique', 120);
            $t->string('responsable', 120);
            $t->string('telephone', 20);
            $t->unsignedInteger('ville_id')->nullable();
            $t->unsignedInteger('categorie_id')->nullable();
            $t->text('description');
            $t->enum('statut', ['en_attente', 'en_verification', 'acceptee', 'refusee'])->default('en_attente');
            $t->text('motif_refus')->nullable();
            $t->foreignId('boutique_id')->nullable()->constrained('boutiques')->nullOnDelete();
            $t->foreignId('traite_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('traite_le')->nullable();
            $t->index('statut');
            $t->foreign('pays_id')->references('id')->on('pays')->restrictOnDelete();
        });

        Schema::create('produits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->unsignedInteger('categorie_id');
            $t->string('reference', 24)->unique();
            $t->string('nom', 160);
            $t->string('slug', 180);
            $t->text('description')->nullable();
            // TOUJOURS TTC : les lois de la zone imposent l'affichage
            // du prix toutes taxes comprises au consommateur.
            $t->unsignedInteger('prix_ttc_cfa');
            $t->unsignedInteger('poids_g')->nullable();
            $t->boolean('actif')->default(true);
            $t->boolean('tracable')->default(false)->comment('Éligible aux étiquettes QR');

            // Une plateforme qui vend de l'anti-contrefaçon ne peut pas
            // laisser publier n'importe quoi dans son propre catalogue.
            $t->enum('statut_moderation', ['brouillon', 'en_attente', 'publie', 'rejete', 'retire'])
              ->default('en_attente');
            $t->text('motif_moderation')->nullable();
            $t->foreignId('modere_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->timestamp('modere_le')->nullable();

            $t->unsignedInteger('vues')->default(0);
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('modifie_le')->useCurrent()->useCurrentOnUpdate();
            $t->softDeletes('supprime_le');

            $t->index(['categorie_id', 'statut_moderation', 'actif'], 'idx_produits_categorie');
            $t->index(['boutique_id', 'actif']);
            $t->index('prix_ttc_cfa');
            $t->foreign('categorie_id')->references('id')->on('categories')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE produits ADD FULLTEXT ft_produits (nom, description)');

        Schema::create('variantes_produit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $t->string('sku', 40)->unique()->comment('Imprimable en code-barres');
            $t->string('libelle', 120)->comment('« Taille L — Bleu indigo », ou « Standard »');
            $t->unsignedInteger('prix_ttc_cfa')->nullable()->comment('NULL = reprend le prix du produit');
            // Volontairement signé : une survente constatée doit être
            // visible en base plutôt que masquée à zéro.
            $t->integer('stock')->default(0);
            $t->unsignedInteger('seuil_alerte')->default(3);
            $t->unsignedInteger('poids_g')->nullable();
            $t->boolean('defaut')->default(false);
            $t->boolean('actif')->default(true);
            $t->index(['produit_id', 'actif']);
        });

        Schema::create('attributs', function (Blueprint $t) {
            $t->smallIncrements('id');
            $t->string('code', 30)->unique();
            $t->string('nom', 60);
        });

        Schema::create('variante_attributs', function (Blueprint $t) {
            $t->foreignId('variante_id')->constrained('variantes_produit')->cascadeOnDelete();
            $t->unsignedSmallInteger('attribut_id');
            $t->string('valeur', 80);
            $t->primary(['variante_id', 'attribut_id']);
            $t->foreign('attribut_id')->references('id')->on('attributs')->cascadeOnDelete();
        });

        /*
         * Quatre tailles préparées à l'avance, jamais de
         * redimensionnement à la volée : servir une photo de 3 Mo prise
         * au téléphone rend la vitrine inutilisable en 2G.
         */
        Schema::create('medias', function (Blueprint $t) {
            $t->id();
            $t->string('proprietaire_type', 30);
            $t->unsignedBigInteger('proprietaire_id');
            $t->string('chemin_original');
            $t->string('chemin_grand')->nullable()->comment('1200 px — fiche produit');
            $t->string('chemin_vignette')->nullable()->comment('400 px — grille de la vitrine');
            $t->string('chemin_miniature')->nullable()->comment('100 px — panier et listes');
            $t->unsignedSmallInteger('largeur')->nullable();
            $t->unsignedSmallInteger('hauteur')->nullable();
            $t->unsignedInteger('poids_octets')->nullable();
            $t->string('texte_alternatif')->nullable();
            $t->smallInteger('ordre')->default(0);
            $t->timestamp('cree_le')->useCurrent();
            $t->index(['proprietaire_type', 'proprietaire_id', 'ordre'], 'idx_medias_proprietaire');
        });
    }

    public function down(): void
    {
        foreach (['medias', 'variante_attributs', 'attributs', 'variantes_produit', 'produits',
                  'candidatures', 'documents_boutique', 'boutiques', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 4/8 — Livraison et commandes
 *
 * LA DÉCISION STRUCTURANTE : une commande client est ÉCLATÉE en autant
 * de sous-commandes que de boutiques. Chacune est préparée, expédiée,
 * livrée, contestée et payée indépendamment.
 *
 * Nouveautés v2 par rapport à la première conception :
 *  · le paiement à la livraison, mode dominant sur le marché ;
 *  · la preuve de livraison par code à usage unique ;
 *  · les points de retrait ;
 *  · le panier persistant, qui rend possible la relance d'abandon ;
 *  · le délai de rétractation, calculé selon le pays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones_livraison', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedTinyInteger('pays_id');
            $t->unsignedInteger('ville_id')->nullable();
            $t->string('quartier', 80)->nullable()->comment('NULL = tarif par défaut de la ville');
            $t->unsignedInteger('frais_base_cfa');
            $t->unsignedInteger('frais_boutique_sup_cfa')->default(0)
              ->comment('Chaque boutique en plus = un point de collecte en plus pour le livreur');
            $t->unsignedInteger('frais_par_kg_cfa')->default(0);
            $t->unsignedTinyInteger('delai_estime_jours')->default(2);
            $t->boolean('paiement_livraison_autorise')->default(true)
              ->comment('Le paiement en espèces se refuse dans les zones à risque');
            $t->boolean('active')->default(true);
            $t->unique(['pays_id', 'ville_id', 'quartier'], 'uk_zones');
            $t->foreign('pays_id')->references('id')->on('pays')->cascadeOnDelete();
            $t->foreign('ville_id')->references('id')->on('villes')->cascadeOnDelete();
        });

        // Moins cher que la livraison à domicile, et adapté à l'absence
        // d'adressage : le client connaît la boutique du quartier, pas
        // son propre code postal.
        Schema::create('points_relais', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedTinyInteger('pays_id');
            $t->unsignedInteger('ville_id');
            $t->string('nom', 120);
            $t->string('adresse');
            $t->string('repere')->nullable();
            $t->string('telephone', 20);
            $t->string('horaires')->nullable();
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            $t->unsignedInteger('frais_cfa')->default(0);
            $t->unsignedSmallInteger('capacite_colis')->nullable();
            $t->boolean('actif')->default(true);
            $t->index(['ville_id', 'actif']);
            $t->foreign('pays_id')->references('id')->on('pays')->cascadeOnDelete();
            $t->foreign('ville_id')->references('id')->on('villes')->cascadeOnDelete();
        });

        Schema::create('transporteurs', function (Blueprint $t) {
            $t->smallIncrements('id');
            $t->unsignedTinyInteger('pays_id');
            $t->string('nom', 80);
            $t->enum('type', ['interne', 'partenaire', 'vendeur'])->default('partenaire');
            $t->string('telephone', 20)->nullable();
            // Un transporteur qui encaisse manipule l'argent des
            // vendeurs : il se suit comme un compte, pas comme un
            // simple prestataire.
            $t->boolean('encaisse_especes')->default(false);
            $t->boolean('actif')->default(true);
            $t->foreign('pays_id')->references('id')->on('pays')->cascadeOnDelete();
        });

        Schema::create('paniers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->char('jeton_session', 40)->nullable()->comment('Panier anonyme, rattaché au compte à la connexion');
            $t->unsignedTinyInteger('pays_id');
            $t->enum('statut', ['actif', 'abandonne', 'converti', 'expire'])->default('actif');
            $t->timestamp('relance_envoyee_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('modifie_le')->useCurrent()->useCurrentOnUpdate();
            $t->index(['utilisateur_id', 'statut']);
            $t->index('jeton_session');
            $t->index(['statut', 'modifie_le'], 'idx_paniers_relance');
        });

        Schema::create('lignes_panier', function (Blueprint $t) {
            $t->id();
            $t->foreignId('panier_id')->constrained('paniers')->cascadeOnDelete();
            $t->foreignId('variante_id')->constrained('variantes_produit')->cascadeOnDelete();
            $t->unsignedSmallInteger('quantite');
            $t->timestamp('ajoute_le')->useCurrent();
            $t->unique(['panier_id', 'variante_id']);
        });

        Schema::create('commandes', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 24)->unique()->comment('BF-CMD-2026-000001');
            $t->unsignedTinyInteger('pays_id');
            $t->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();

            // Recopié : la commande d'hier garde l'adresse d'hier.
            $t->string('client_nom', 120);
            $t->string('client_telephone', 20);
            $t->unsignedInteger('ville_id')->nullable();
            $t->string('quartier', 120);
            $t->string('repere')->nullable()->comment('Indispensable : pas d\'adressage postal fiable');

            $t->enum('mode_livraison', ['domicile', 'point_relais', 'retrait_boutique'])->default('domicile');
            $t->unsignedInteger('point_relais_id')->nullable();

            // « especes_livraison » est le mode dominant du marché : il
            // devait exister dès la conception, pas être ajouté après.
            $t->enum('mode_paiement', ['mobile_money', 'carte', 'virement', 'especes_livraison']);
            $t->unsignedSmallInteger('operateur_paiement_id')->nullable();
            $t->enum('statut_paiement', ['attente', 'autorise', 'encaisse', 'partiel', 'echoue', 'rembourse'])
              ->default('attente');
            $t->enum('statut', ['brouillon', 'confirmee', 'en_preparation', 'partiellement_livree',
                                'livree', 'retractee', 'annulee'])->default('brouillon');

            $t->unsignedInteger('total_articles_ttc_cfa')->default(0);
            $t->unsignedInteger('total_tva_cfa')->default(0);
            $t->unsignedInteger('total_frais_livraison_cfa')->default(0);
            $t->unsignedInteger('total_remise_cfa')->default(0);
            $t->unsignedInteger('total_a_payer_cfa')->default(0);

            $t->unsignedInteger('promotion_id')->nullable();
            $t->char('langue', 5)->default('fr');
            $t->enum('canal', ['web', 'mobile', 'telephone', 'agent'])->default('web');

            // Le contrat doit être archivé et le récapitulatif accepté
            // conservé : obligation explicite au Sénégal.
            $t->string('cgv_version', 20)->nullable();
            $t->timestamp('cgv_acceptees_le')->nullable();
            $t->date('retractation_avant')->nullable()->comment('Calculé selon le pays à la livraison');

            $t->timestamp('confirmee_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('modifie_le')->useCurrent()->useCurrentOnUpdate();

            $t->index('client_telephone');
            $t->index(['pays_id', 'statut']);
            $t->foreign('pays_id')->references('id')->on('pays')->restrictOnDelete();
            $t->foreign('point_relais_id')->references('id')->on('points_relais')->nullOnDelete();
        });

        Schema::create('sous_commandes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $t->foreignId('boutique_id')->constrained('boutiques')->restrictOnDelete();
            $t->string('reference', 32)->unique();

            $t->enum('statut', ['a_preparer', 'prete', 'expediee', 'livree', 'retournee', 'annulee'])
              ->default('a_preparer');

            /*
             * ÉTAT DES FONDS — toujours distinct du statut logistique.
             * « attente_encaissement » est le cas du paiement à la
             * livraison : rien n'a été encaissé, on ne peut donc rien
             * séquestrer. « impaye » est le colis refusé à la porte.
             */
            $t->enum('etat_fonds', ['attente_encaissement', 'sequestre', 'reverse', 'rembourse', 'impaye'])
              ->default('sequestre');

            $t->unsignedInteger('montant_articles_ttc_cfa')->default(0);
            $t->unsignedInteger('montant_tva_cfa')->default(0);
            $t->unsignedInteger('frais_livraison_cfa')->default(0);
            $t->unsignedInteger('remise_cfa')->default(0);

            // Taux FIGÉS à la commande : renégocier n'affecte pas le passé.
            $t->decimal('taux_commission_pct', 5, 2);
            $t->unsignedInteger('commission_cfa')->default(0);
            $t->decimal('taux_retenue_source_pct', 5, 2)->default(0);
            $t->unsignedInteger('retenue_source_cfa')->default(0)
              ->comment('N\'est PAS un revenu de la plateforme : dû au fisc');
            $t->unsignedInteger('montant_net_cfa')->default(0)
              ->comment('articles − commission − retenue à la source');

            $t->timestamp('expedie_le')->nullable();
            $t->timestamp('livre_le')->nullable();
            $t->timestamp('confirme_par_client_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('modifie_le')->useCurrent()->useCurrentOnUpdate();

            $t->index(['boutique_id', 'statut']);
            $t->index('etat_fonds');
        });

        Schema::create('lignes_commande', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sous_commande_id')->constrained('sous_commandes')->cascadeOnDelete();
            $t->foreignId('variante_id')->nullable()->constrained('variantes_produit')->nullOnDelete();
            // Tout est recopié : la facture d'hier doit rester juste.
            $t->string('sku', 40);
            $t->string('nom_produit', 160);
            $t->string('libelle_variante', 120);
            $t->unsignedInteger('prix_unitaire_ttc_cfa');
            $t->decimal('taux_tva_pct', 5, 2)->default(0);
            $t->unsignedSmallInteger('quantite');
            $t->unsignedInteger('total_ttc_cfa');
            $t->unsignedInteger('total_tva_cfa')->default(0);
        });

        /*
         * PREUVE DE LIVRAISON. Sans elle, un litige « je n'ai rien
         * reçu » se tranche à pile ou face. Le code à usage unique est
         * la solution la plus simple : le client le reçoit par SMS, le
         * livreur le saisit devant lui.
         */
        Schema::create('expeditions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sous_commande_id')->constrained('sous_commandes')->cascadeOnDelete();
            $t->unsignedSmallInteger('transporteur_id')->nullable();
            $t->foreignId('livreur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->string('code_suivi', 60)->nullable();
            $t->char('code_livraison', 6)->nullable()->comment('Code à usage unique envoyé au client');
            $t->timestamp('code_valide_le')->nullable();
            $t->unsignedBigInteger('preuve_media_id')->nullable()->comment('Photo du colis remis');
            $t->string('signature_nom', 120)->nullable();
            $t->unsignedTinyInteger('tentatives')->default(0);
            $t->enum('statut', ['prevue', 'en_cours', 'livree', 'echouee', 'retour_expediteur'])->default('prevue');
            $t->string('motif_echec')->nullable();
            $t->timestamp('expedie_le')->nullable();
            $t->timestamp('livre_le')->nullable();
            $t->index('sous_commande_id');
            $t->foreign('transporteur_id')->references('id')->on('transporteurs')->nullOnDelete();
        });

        // Table en INSERTION SEULE : jamais d'UPDATE ni de DELETE.
        // Une ligne d'historique modifiable ne prouverait plus rien.
        Schema::create('evenements_commande', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sous_commande_id')->constrained('sous_commandes')->cascadeOnDelete();
            $t->string('type', 48);
            $t->json('donnees')->nullable();
            $t->foreignId('auteur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->enum('auteur_type', ['systeme', 'client', 'vendeur', 'livreur', 'agent', 'admin'])
              ->default('systeme');
            $t->timestamp('cree_le')->useCurrent();
            $t->index(['sous_commande_id', 'cree_le'], 'idx_evenements');
        });
    }

    public function down(): void
    {
        foreach (['evenements_commande', 'expeditions', 'lignes_commande', 'sous_commandes',
                  'commandes', 'lignes_panier', 'paniers', 'transporteurs', 'points_relais',
                  'zones_livraison'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

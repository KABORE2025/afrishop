<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 5/8 — Argent : transactions, grand livre, cantonnement, reversements
 *
 * LE BLOC LE PLUS SENSIBLE, et celui qui a le plus changé.
 *
 * Constat de la v2 : aucun agrégateur UEMOA ne propose de sous-compte
 * marchand avec séquestre. La plateforme encaisse donc sur un compte
 * unique et détient de fait des fonds appartenant aux boutiques.
 *
 * D'où trois exigences directement modélisées :
 *  · un GRAND LIVRE en écriture seule, seule source de vérité sur ce
 *    que la plateforme doit ;
 *  · un CANTONNEMENT rapproché chaque jour (instruction BCEAO
 *    001-01-2024, art. 48) ;
 *  · une RÉCONCILIATION avec le prestataire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions_paiement', function (Blueprint $t) {
            $t->id();
            $t->foreignId('commande_id')->constrained('commandes')->restrictOnDelete();
            $t->unsignedSmallInteger('prestataire_id')->nullable();
            $t->unsignedSmallInteger('operateur_paiement_id')->nullable();
            $t->enum('sens', ['encaissement', 'remboursement'])->default('encaissement');
            $t->unsignedInteger('montant_cfa');
            $t->unsignedInteger('frais_psp_cfa')->default(0);
            $t->string('reference_externe', 100)->nullable();
            $t->enum('statut', ['initiee', 'en_attente', 'reussie', 'echouee', 'expiree', 'annulee'])
              ->default('initiee');
            $t->string('code_erreur', 60)->nullable();
            $t->string('message_erreur')->nullable();
            // Conservé pour rejouer un webhook et prouver ce que le PSP
            // a réellement envoyé en cas de contestation.
            $t->json('charge_utile_webhook')->nullable();
            $t->timestamp('initiee_le')->useCurrent();
            $t->timestamp('finalisee_le')->nullable();
            // L'unicité de la référence externe est la garantie
            // d'idempotence : les PSP rejouent leurs webhooks.
            $t->unique(['prestataire_id', 'reference_externe'], 'uk_tx_externe');
            $t->index(['commande_id', 'statut']);
            $t->index(['statut', 'initiee_le']);
            $t->foreign('prestataire_id')->references('id')->on('prestataires_paiement')->nullOnDelete();
        });

        // Le livreur détient physiquement de l'argent qui n'est pas le
        // sien. Sans suivi ligne à ligne, la démarque est invisible.
        Schema::create('encaissements_especes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expedition_id')->constrained('expeditions')->cascadeOnDelete();
            $t->foreignId('livreur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->unsignedSmallInteger('transporteur_id')->nullable();
            $t->unsignedInteger('montant_du_cfa');
            $t->unsignedInteger('montant_percu_cfa')->default(0);
            $t->enum('statut', ['a_encaisser', 'encaisse', 'remis', 'manquant', 'refuse_client'])
              ->default('a_encaisser');
            $t->timestamp('remis_le')->nullable();
            $t->string('bordereau_ref', 60)->nullable();
            $t->string('ecart_commentaire')->nullable();
            $t->index(['statut', 'remis_le']);
            $t->index(['livreur_id', 'statut']);
        });

        /*
         * GRAND LIVRE — table en écriture seule.
         *
         * Le solde d'une boutique n'est JAMAIS une colonne : c'est la
         * somme de ses mouvements. Une colonne « solde » finit toujours
         * par diverger de son historique, et on ne sait alors plus
         * laquelle des deux croire.
         *
         * Une erreur se corrige par une écriture inverse
         * (annule_mouvement_id), jamais par un UPDATE.
         */
        Schema::create('mouvements_compte', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->nullable()->constrained('boutiques')->restrictOnDelete()
              ->comment('NULL = compte de la plateforme (commissions, frais)');
            $t->enum('type', ['vente', 'commission', 'retenue_source', 'frais_psp', 'remboursement',
                              'reversement', 'ajustement', 'frais_retour', 'penalite']);
            // Sens explicite plutôt qu'un montant signé : plus verbeux,
            // mais impossible à additionner de travers en SQL.
            $t->enum('sens', ['credit', 'debit']);
            $t->unsignedInteger('montant_cfa');
            $t->char('devise', 3)->default('XOF');
            $t->string('piece_type', 30)->comment('sous_commande, reversement, retour, litige…');
            $t->unsignedBigInteger('piece_id');
            $t->string('libelle');
            $t->unsignedBigInteger('annule_mouvement_id')->nullable()->comment('Contrepassation');
            $t->timestamp('cree_le')->useCurrent();
            $t->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->index(['boutique_id', 'cree_le']);
            $t->index(['piece_type', 'piece_id']);
        });

        // L'écart doit être nul. S'il ne l'est pas, on le voit le jour
        // même — c'est tout l'objet de cette table.
        Schema::create('cantonnement_journalier', function (Blueprint $t) {
            $t->id();
            $t->date('date_arrete');
            $t->unsignedTinyInteger('pays_id');
            $t->bigInteger('solde_banque_cfa')->comment('Relevé du compte de cantonnement');
            $t->bigInteger('solde_grand_livre_cfa')->comment('Somme des dettes envers les boutiques');
            $t->bigInteger('ecart_cfa')->default(0);
            $t->enum('statut', ['conforme', 'ecart_a_expliquer', 'regularise'])->default('conforme');
            $t->text('commentaire')->nullable();
            $t->foreignId('valide_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->timestamp('cree_le')->useCurrent();
            $t->unique(['date_arrete', 'pays_id']);
            $t->foreign('pays_id')->references('id')->on('pays')->restrictOnDelete();
        });

        Schema::create('reconciliations_psp', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('prestataire_id');
            $t->date('date_arrete');
            $t->unsignedInteger('nb_operations_psp')->default(0);
            $t->bigInteger('montant_psp_cfa')->default(0);
            $t->unsignedInteger('nb_operations_local')->default(0);
            $t->bigInteger('montant_local_cfa')->default(0);
            $t->bigInteger('ecart_montant_cfa')->default(0);
            $t->enum('statut', ['en_cours', 'conforme', 'ecart', 'regularise'])->default('en_cours');
            $t->json('detail_ecarts')->nullable()->comment('Références des opérations non appariées');
            $t->timestamp('cree_le')->useCurrent();
            $t->unique(['prestataire_id', 'date_arrete']);
            $t->foreign('prestataire_id')->references('id')->on('prestataires_paiement')->restrictOnDelete();
        });

        Schema::create('reversements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained('boutiques')->restrictOnDelete();
            $t->string('reference', 28)->unique();
            $t->unsignedSmallInteger('prestataire_id')->nullable();
            $t->date('periode_debut');
            $t->date('periode_fin');
            $t->unsignedInteger('montant_brut_cfa');
            $t->unsignedInteger('commission_cfa');
            $t->unsignedInteger('retenue_source_cfa')->default(0);
            $t->unsignedInteger('frais_transfert_cfa')->default(0);
            $t->unsignedInteger('montant_net_cfa');
            $t->enum('statut', ['a_payer', 'en_cours', 'paye', 'echoue', 'suspendu'])->default('a_payer');
            $t->string('reference_externe', 100)->nullable();
            $t->text('motif_echec')->nullable();
            $t->string('motif_suspension')->nullable()
              ->comment('Plafond de portefeuille atteint, KYC incomplet, contrôle en cours');
            $t->timestamp('execute_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->index(['boutique_id', 'statut']);
        });

        Schema::create('reversement_lignes', function (Blueprint $t) {
            $t->foreignId('reversement_id')->constrained('reversements')->cascadeOnDelete();
            $t->foreignId('sous_commande_id')->constrained('sous_commandes')->restrictOnDelete();
            $t->primary(['reversement_id', 'sous_commande_id']);
            // PROTECTION ANTI-DOUBLE-PAIEMENT au niveau de la base :
            // une vente ne peut figurer que dans un seul reversement,
            // quoi que fasse l'application.
            $t->unique('sous_commande_id', 'uk_une_vente_un_reversement');
        });
    }

    public function down(): void
    {
        foreach (['reversement_lignes', 'reversements', 'reconciliations_psp', 'cantonnement_journalier',
                  'mouvements_compte', 'encaissements_especes', 'transactions_paiement'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

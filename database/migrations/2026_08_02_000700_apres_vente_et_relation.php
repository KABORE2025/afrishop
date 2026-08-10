<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7/8 — Après-vente, relation client, promotions
 *
 * RETOUR ≠ LITIGE. Un litige suppose un tort : produit défectueux ou
 * absent. Un retour suppose un produit conforme et un client qui change
 * d'avis — c'est un droit dans plusieurs pays de la zone. La question
 * qui fâche est « qui paie le transport retour », et elle se modélise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retours', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sous_commande_id')->constrained('sous_commandes')->cascadeOnDelete();
            $t->string('reference', 32)->unique();
            $t->enum('type', ['retractation', 'echange', 'remboursement']);
            $t->enum('motif', ['ne_convient_pas', 'taille_incorrecte', 'erreur_commande', 'autre']);
            $t->text('commentaire')->nullable();
            $t->enum('statut', ['demande', 'accepte', 'refuse', 'en_transit', 'recu', 'rembourse', 'clos'])
              ->default('demande');
            $t->text('motif_refus')->nullable();
            // Rétractation légale : généralement le client.
            // Erreur du vendeur : le vendeur. Geste commercial : nous.
            $t->enum('frais_a_la_charge', ['client', 'boutique', 'plateforme'])->default('client');
            $t->unsignedInteger('frais_retour_cfa')->default(0);
            $t->unsignedInteger('montant_rembourse_cfa')->default(0);
            $t->timestamp('demande_le')->useCurrent();
            $t->timestamp('traite_le')->nullable();
            $t->timestamp('recu_le')->nullable();
            $t->index('statut');
        });

        Schema::create('lignes_retour', function (Blueprint $t) {
            $t->id();
            $t->foreignId('retour_id')->constrained('retours')->cascadeOnDelete();
            $t->foreignId('ligne_commande_id')->constrained('lignes_commande')->cascadeOnDelete();
            $t->unsignedSmallInteger('quantite');
            $t->enum('etat_constate', ['neuf', 'ouvert', 'abime', 'incomplet'])->nullable()
              ->comment('Renseigné à la réception : conditionne le remboursement');
        });

        Schema::create('litiges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sous_commande_id')->constrained('sous_commandes')->cascadeOnDelete();
            $t->string('reference', 32)->unique();
            $t->enum('motif', ['non_recu', 'endommage', 'non_conforme', 'incomplet', 'contrefacon', 'autre']);
            $t->text('description');
            $t->enum('statut', ['ouvert', 'en_examen', 'resolu_client', 'resolu_boutique', 'clos'])
              ->default('ouvert');
            $t->foreignId('ouvert_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->foreignId('traite_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->text('resolution')->nullable()->comment('Toujours motivée : visible des deux parties');
            // Voie de recours du vendeur. La réputation est devenue un
            // actif pour lui : sans recours, un client de mauvaise foi
            // peut abîmer durablement une boutique honnête.
            $t->timestamp('conteste_par_boutique_le')->nullable();
            $t->text('argument_boutique')->nullable();
            $t->timestamp('ouvert_le')->useCurrent();
            $t->timestamp('resolu_le')->nullable();
            $t->index('statut');
        });

        // Seul un acheteur AYANT REÇU le produit peut déposer un avis.
        // Sans cette contrainte, la section se remplit de faux avis.
        Schema::create('avis', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sous_commande_id')->constrained('sous_commandes')->cascadeOnDelete();
            $t->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $t->foreignId('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->string('auteur_affiche', 80)->comment('Prénom + initiale : « Aminata S. »');
            $t->unsignedTinyInteger('note');
            $t->string('titre', 120)->nullable();
            $t->text('commentaire')->nullable();
            $t->enum('statut', ['en_attente', 'publie', 'rejete'])->default('en_attente');
            $t->string('motif_rejet')->nullable();
            // Droit de réponse : un avis négatif sans réponse possible
            // est perçu comme une condamnation sans procès.
            $t->text('reponse_boutique')->nullable();
            $t->timestamp('reponse_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->unique(['sous_commande_id', 'produit_id'], 'uk_avis');
            $t->index(['produit_id', 'statut']);
            $t->index(['boutique_id', 'statut']);
        });

        Schema::create('tickets_support', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 24)->unique();
            $t->unsignedTinyInteger('pays_id');
            $t->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->string('telephone', 20);
            $t->enum('canal', ['telephone', 'whatsapp', 'sms', 'web', 'agent'])->default('telephone')
              ->comment('Le téléphone est le canal principal du marché, pas l\'e-mail');
            $t->string('sujet', 160);
            $t->foreignId('commande_id')->nullable()->constrained('commandes')->nullOnDelete();
            $t->enum('priorite', ['basse', 'normale', 'haute', 'urgente'])->default('normale');
            $t->enum('statut', ['ouvert', 'en_cours', 'attente_client', 'resolu', 'clos'])->default('ouvert');
            $t->foreignId('assigne_a_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            // Un délai annoncé mais non mesuré est pire que pas de délai.
            $t->timestamp('echeance_reponse')->nullable();
            $t->timestamp('premiere_reponse_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('clos_le')->nullable();
            $t->index(['statut', 'priorite', 'echeance_reponse']);
            $t->foreign('pays_id')->references('id')->on('pays')->restrictOnDelete();
        });

        Schema::create('messages_ticket', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('tickets_support')->cascadeOnDelete();
            $t->foreignId('auteur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->enum('auteur_type', ['client', 'agent', 'systeme', 'vendeur']);
            $t->text('corps');
            $t->boolean('interne')->default(false)->comment('Note interne, invisible du client');
            $t->timestamp('cree_le')->useCurrent();
            $t->index(['ticket_id', 'cree_le']);
        });

        // Modifier un texte ne doit jamais demander de redéployer.
        Schema::create('gabarits_notification', function (Blueprint $t) {
            $t->smallIncrements('id');
            $t->string('code', 60);
            $t->enum('canal', ['sms', 'push', 'email', 'whatsapp']);
            $t->char('langue', 5)->default('fr');
            $t->string('sujet', 160)->nullable();
            $t->text('corps')->comment('Variables entre accolades : {client_nom}, {reference}');
            $t->unsignedSmallInteger('longueur_max')->nullable()
              ->comment('Au-delà de 160 caractères, on paie deux SMS');
            $t->boolean('actif')->default(true);
            $t->unique(['code', 'canal', 'langue']);
        });

        /*
         * Journal des envois. Trois usages : prouver qu'on a prévenu le
         * client, diagnostiquer les non-réceptions, et SURVEILLER LE
         * COÛT — poste de dépense récurrent le plus sous-estimé d'une
         * place de marché.
         */
        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('gabarit_id')->nullable();
            $t->foreignId('destinataire_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->string('telephone', 20)->nullable();
            $t->string('email', 180)->nullable();
            $t->enum('canal', ['sms', 'push', 'email', 'whatsapp']);
            $t->text('corps_envoye')->comment('Après substitution des variables');
            $t->enum('statut', ['en_file', 'envoye', 'remis', 'echoue', 'rejete'])->default('en_file');
            $t->string('fournisseur', 40)->nullable();
            $t->string('reference_externe', 100)->nullable();
            $t->unsignedInteger('cout_cfa')->default(0);
            $t->unsignedTinyInteger('nb_segments')->default(1);
            $t->string('erreur')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('envoye_le')->nullable();
            $t->index(['statut', 'cree_le']);
            $t->index(['destinataire_id', 'cree_le']);
            $t->foreign('gabarit_id')->references('id')->on('gabarits_notification')->nullOnDelete();
        });

        /*
         * QUI FINANCE LA REMISE ? Si c'est la plateforme, elle rogne sa
         * commission. Si c'est la boutique, elle doit l'avoir ACCEPTÉ.
         * Une remise imposée à un artisan sans son accord est le meilleur
         * moyen de le faire partir.
         */
        Schema::create('promotions', function (Blueprint $t) {
            $t->increments('id');
            $t->string('code', 30)->nullable()->unique()->comment('NULL = promotion automatique');
            $t->string('libelle', 120);
            $t->unsignedTinyInteger('pays_id')->nullable()->comment('NULL = tous les pays ouverts');
            $t->enum('type', ['pourcentage', 'montant_fixe', 'livraison_offerte']);
            $t->unsignedInteger('valeur')->default(0);
            $t->enum('financeur', ['plateforme', 'boutique', 'partage'])->default('plateforme');
            $t->decimal('part_boutique_pct', 5, 2)->default(0);
            $t->foreignId('boutique_id')->nullable()->constrained('boutiques')->cascadeOnDelete();
            $t->unsignedInteger('categorie_id')->nullable();
            $t->unsignedInteger('montant_minimum_cfa')->default(0);
            $t->unsignedInteger('plafond_remise_cfa')->nullable();
            $t->unsignedInteger('usages_max')->nullable();
            $t->unsignedSmallInteger('usages_max_par_client')->default(1);
            $t->unsignedInteger('usages_actuels')->default(0);
            $t->dateTime('debut_le');
            $t->dateTime('fin_le');
            $t->timestamp('accepte_par_boutique_le')->nullable()
              ->comment('Obligatoire si la boutique finance tout ou partie');
            $t->boolean('active')->default(true);
            $t->index(['active', 'debut_le', 'fin_le']);
        });

        Schema::create('utilisations_promotion', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('promotion_id');
            $t->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $t->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->unsignedInteger('remise_cfa');
            $t->timestamp('cree_le')->useCurrent();
            $t->unique(['promotion_id', 'commande_id'], 'uk_utilisation');
            $t->index(['promotion_id', 'utilisateur_id']);
            $t->foreign('promotion_id')->references('id')->on('promotions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['utilisations_promotion', 'promotions', 'notifications', 'gabarits_notification',
                  'messages_ticket', 'tickets_support', 'avis', 'litiges', 'lignes_retour', 'retours'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

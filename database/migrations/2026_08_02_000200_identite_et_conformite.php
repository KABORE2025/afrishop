<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2/8 — Identité et conformité « données personnelles »
 *
 * Les lois de la zone (Burkina 001-2021, Sénégal 2008-12, Côte d'Ivoire
 * 2013-450, Bénin Code du numérique) imposent une formalité préalable,
 * un consentement traçable, une durée de conservation limitée et une
 * réponse aux demandes d'accès sous deux mois.
 *
 * Ces obligations SE MODÉLISENT. Les traiter comme un sujet juridique
 * séparé du code, c'est découvrir au moment du contrôle qu'aucune
 * preuve n'existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utilisateurs', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('pays_id');
            $t->string('nom', 120);
            $t->string('telephone', 20)->unique()->comment('E.164 — identifiant de connexion');
            $t->string('email', 180)->nullable()->unique();
            $t->string('mot_de_passe')->nullable();
            $t->char('langue', 5)->default('fr');
            $t->enum('role', ['client', 'vendeur', 'livreur', 'agent', 'admin'])->default('client');

            // Détermine les plafonds de monnaie électronique applicables
            // et la possibilité d'être payé en tant que vendeur.
            $t->enum('kyc_niveau', ['aucun', 'simplifie', 'complet'])->default('aucun');
            $t->timestamp('kyc_valide_le')->nullable();

            $t->timestamp('telephone_verifie_le')->nullable();
            $t->timestamp('derniere_connexion')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->timestamp('modifie_le')->useCurrent()->useCurrentOnUpdate();

            // Anonymisation plutôt que suppression : les commandes
            // doivent rester comptablement exploitables après
            // l'effacement du compte.
            $t->timestamp('anonymise_le')->nullable();
            $t->softDeletes('supprime_le');

            $t->index(['pays_id', 'role']);
            $t->foreign('pays_id')->references('id')->on('pays')->restrictOnDelete();
        });

        // Sert directement à remplir les formulaires de déclaration
        // auprès de la CIL, de la CDP ou de l'ARTCI.
        Schema::create('registre_traitements', function (Blueprint $t) {
            $t->smallIncrements('id');
            $t->string('code', 40)->unique();
            $t->string('finalite');
            $t->enum('base_legale', ['consentement', 'contrat', 'obligation_legale', 'interet_legitime']);
            $t->text('categories_donnees');
            $t->text('destinataires')->nullable();
            $t->unsignedInteger('duree_conservation_jours')->nullable();
            $t->char('pays_hebergement', 2)->nullable();
            $t->string('declaration_ref', 80)->nullable()->comment('Numéro de récépissé');
            $t->date('declare_le')->nullable();
        });

        // Un consentement dont on ne peut prouver ni la date, ni la
        // version du texte affiché, ni le canal ne vaut rien en contrôle.
        Schema::create('consentements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->string('telephone', 20)->nullable();
            $t->enum('finalite', ['cgu', 'confidentialite', 'prospection_sms', 'prospection_email', 'cookies']);
            $t->string('version_texte', 20)->comment('Version du document affiché au moment du clic');
            $t->enum('canal', ['web', 'mobile', 'sms', 'papier', 'telephone']);
            $t->boolean('accorde');
            $t->char('ip_hachee', 64)->nullable();
            $t->timestamp('donne_le')->useCurrent();
            $t->timestamp('retire_le')->nullable();
            $t->index(['utilisateur_id', 'finalite', 'donne_le']);
        });

        Schema::create('demandes_droits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->string('telephone', 20);
            $t->enum('type', ['acces', 'rectification', 'effacement', 'opposition', 'portabilite']);
            $t->text('detail')->nullable();
            $t->enum('statut', ['recue', 'en_cours', 'satisfaite', 'refusee'])->default('recue');
            $t->text('motif_refus')->nullable();
            $t->foreignId('traite_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->timestamp('recue_le')->useCurrent();
            // Le dépassement du délai est sanctionné : la colonne existe
            // pour alerter AVANT, pas pour constater après.
            $t->date('echeance');
            $t->timestamp('traitee_le')->nullable();
            $t->index(['statut', 'echeance']);
        });

        // Qui a CONSULTÉ quoi. Seul moyen de répondre à « qui a vu mon dossier ? ».
        Schema::create('journal_acces_donnees', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agent_id');
            $t->string('personne_type', 30);
            $t->unsignedBigInteger('personne_id');
            $t->string('action', 40);
            $t->string('motif')->nullable();
            $t->char('ip_hachee', 64)->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->index(['personne_type', 'personne_id', 'cree_le'], 'idx_journal_acces');
            $t->index(['agent_id', 'cree_le']);
        });

        // Qui a DÉCIDÉ quoi. Distinct du précédent : sans lui, il est
        // impossible de savoir qui a remboursé un client ou suspendu
        // une boutique.
        Schema::create('journal_administration', function (Blueprint $t) {
            $t->id();
            $t->foreignId('agent_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->string('action', 60);
            $t->string('cible_type', 40);
            $t->unsignedBigInteger('cible_id')->nullable();
            $t->json('avant')->nullable();
            $t->json('apres')->nullable();
            $t->text('motif')->nullable();
            $t->char('ip_hachee', 64)->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->index(['cible_type', 'cible_id', 'cree_le'], 'idx_journal_admin');
        });
    }

    public function down(): void
    {
        foreach (['journal_administration', 'journal_acces_donnees', 'demandes_droits',
                  'consentements', 'registre_traitements', 'utilisateurs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

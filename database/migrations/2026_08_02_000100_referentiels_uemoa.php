<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ---------------------------------------------------------------------
 * 1/8 — Référentiels UEMOA
 * ---------------------------------------------------------------------
 * Ce qui varie d'un pays à l'autre, et qui ne doit donc JAMAIS être
 * codé en dur : la TVA, les opérateurs de paiement, la retenue à la
 * source, l'obligation de facture certifiée, le délai de rétractation.
 *
 * Bonne nouvelle du périmètre UEMOA : les 8 pays partagent le franc CFA.
 * Le multi-pays ne crée donc pas de multi-devise — ni table de change,
 * ni conversion, ni arrondi de conversion.
 *
 * Le schéma SQL complet et commenté est dans
 * database/schema/afrishop-v2.sql ; il fait référence pour la relecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pays', function (Blueprint $t) {
            $t->tinyIncrements('id');
            $t->char('code_iso2', 2)->unique();
            $t->string('nom', 60);
            $t->char('devise', 3)->default('XOF');
            $t->string('indicatif_telephonique', 6);
            $t->char('langue_defaut', 5)->default('fr');

            $t->unsignedBigInteger('seuil_assujettissement_tva_cfa')->nullable()
              ->comment('200 M en Côte d\'Ivoire, 50 M au Burkina, 10 M en Guinée-Bissau');

            // Le Burkina retient 25 % sur les sommes versées à un
            // vendeur sans numéro fiscal. C'est le taux le plus lourd
            // de la zone, et le premier argument pour accompagner les
            // vendeurs vers l'immatriculation.
            $t->decimal('retenue_source_non_immatricule_pct', 5, 2)->nullable();
            $t->decimal('retenue_source_immatricule_pct', 5, 2)->nullable();
            $t->unsignedInteger('retenue_source_seuil_cfa')->nullable();

            $t->boolean('facture_certifiee_obligatoire')->default(false);
            $t->string('plateforme_facturation', 40)->nullable()
              ->comment('e-MECeF (BJ), FEC (BF), FNE (CI), e-SECeF (NE)');

            $t->unsignedSmallInteger('retractation_jours_ouvrables')->nullable();
            $t->unsignedSmallInteger('retractation_jours_si_defaut_info')->nullable()
              ->comment('Sénégal : 3 mois si l\'information n\'a pas été donnée');

            $t->string('autorite_donnees', 80)->nullable()->comment('CIL, ARTCI, CDP, APDP…');
            $t->boolean('transfert_donnees_autorisation_requise')->default(true);

            $t->boolean('actif')->default(false)
              ->comment('Un pays existe au référentiel avant d\'être ouvert commercialement');
            $t->date('ouvert_le')->nullable();
        });

        /*
         * Taux HISTORISÉS. Un taux n'est jamais une colonne de `pays` :
         * une facture émise en 2026 doit rester recalculable au taux de
         * 2026, même après une loi de finances qui le change.
         */
        Schema::create('taux_taxe', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedTinyInteger('pays_id');
            $t->string('code', 20)->comment('tva_normal, tva_reduit…');
            $t->string('libelle', 80);
            $t->decimal('taux_pct', 5, 2);
            $t->date('date_debut');
            $t->date('date_fin')->nullable()->comment('NULL = en vigueur');
            $t->foreign('pays_id')->references('id')->on('pays')->cascadeOnDelete();
            $t->index(['pays_id', 'code', 'date_debut'], 'idx_taux_pays');
        });

        Schema::create('villes', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedTinyInteger('pays_id');
            $t->string('nom', 80);
            $t->string('slug', 90);
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['pays_id', 'slug']);
            $t->foreign('pays_id')->references('id')->on('pays')->cascadeOnDelete();
        });

        /*
         * Wave n'existe pas au Togo, au Bénin ni en Guinée-Bissau ;
         * Celtiis Cash n'existe qu'au Bénin. Proposer un opérateur
         * absent, c'est garantir un échec de paiement au moment le plus
         * coûteux : juste avant la validation.
         */
        Schema::create('operateurs_paiement', function (Blueprint $t) {
            $t->smallIncrements('id');
            $t->unsignedTinyInteger('pays_id');
            $t->string('code', 30);
            $t->string('nom', 60);
            $t->enum('type', ['mobile_money', 'carte', 'virement', 'especes'])->default('mobile_money');

            // Plafonds réglementaires BCEAO (instruction 008-05-2015) :
            // 200 000 F/mois sans identification, 2 000 000 de solde et
            // 10 000 000 de rechargement mensuel si identifié.
            $t->unsignedInteger('plafond_transaction_cfa')->nullable();
            $t->unsignedInteger('plafond_mensuel_cfa')->nullable();

            $t->decimal('frais_encaissement_pct', 5, 2)->nullable();
            $t->decimal('frais_reversement_pct', 5, 2)->nullable();
            $t->unsignedInteger('frais_reversement_fixe_cfa')->nullable();
            $t->string('logo_url')->nullable();
            $t->smallInteger('ordre')->default(0);
            $t->boolean('actif')->default(true);
            $t->unique(['pays_id', 'code']);
            $t->foreign('pays_id')->references('id')->on('pays')->cascadeOnDelete();
        });

        // On conserve la référence d'agrément : il faut pouvoir prouver
        // qu'on a vérifié que le prestataire est agréé BCEAO avant de
        // lui confier des fonds.
        Schema::create('prestataires_paiement', function (Blueprint $t) {
            $t->smallIncrements('id');
            $t->string('code', 30)->unique();
            $t->string('nom', 80);
            $t->string('agrement_bceao_ref', 80)->nullable();
            $t->date('agrement_verifie_le')->nullable();
            $t->boolean('supporte_payout')->default(true);
            $t->boolean('supporte_split')->default(false)
              ->comment('Aucun prestataire UEMOA ne le proposait au 08/2026');
            $t->boolean('actif')->default(true);
        });

        // Une seule table de traductions plutôt qu'une colonne par
        // langue : ajouter le mooré ne doit pas demander de migration.
        Schema::create('traductions', function (Blueprint $t) {
            $t->id();
            $t->string('entite', 40);
            $t->unsignedBigInteger('entite_id');
            $t->string('champ', 40);
            $t->char('langue', 5);
            $t->text('valeur');
            $t->unique(['entite', 'entite_id', 'champ', 'langue'], 'uk_traductions');
        });

        Schema::create('parametres', function (Blueprint $t) {
            $t->smallIncrements('id');
            $t->unsignedTinyInteger('pays_id')->nullable()->comment('NULL = valeur par défaut');
            $t->string('cle', 60);
            $t->string('valeur');
            $t->enum('type', ['entier', 'decimal', 'booleen', 'texte', 'json'])->default('texte');
            $t->string('libelle', 160);
            $t->timestamp('modifie_le')->useCurrent()->useCurrentOnUpdate();
            $t->unique(['pays_id', 'cle']);
            $t->foreign('pays_id')->references('id')->on('pays')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['parametres', 'traductions', 'prestataires_paiement', 'operateurs_paiement',
                  'villes', 'taux_taxe', 'pays'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

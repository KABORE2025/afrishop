<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 9/9 — Vente au comptoir, remise à transitaire, clients internationaux
 *
 * Trois ajouts dictés par trois décisions de terrain :
 *
 * 1. LA VENTE AU COMPTOIR. Le client entre, choisit, paie en liquide,
 *    repart. C'est la majorité du chiffre d'affaires réel des
 *    artisans, et sans elle le stock en ligne est faux.
 *
 * 2. LA REMISE À TRANSITAIRE. La plateforme ne gère ni le transport
 *    international, ni la douane. Le client désigne son transitaire, on
 *    remet contre preuve, la responsabilité s'arrête là. Ce choix évite
 *    les codes tarifaires, le calcul des droits par pays, les seuils de
 *    franchise et les colis refusés en douane.
 *
 * 3. LE CLIENT À L'ÉTRANGER. Il commande depuis Paris, fait régler par
 *    un proche sur place, et fait livrer à un tiers. L'adresse doit
 *    pouvoir être postale, et le payeur peut ne pas être le client.
 *
 * MONNAIE DE COMPTE : le franc CFA le reste. Les colonnes en `_cfa` ne
 * sont pas renommées — c'est une dette technique consciente, à rouvrir
 * le jour où une boutique sera réellement établie hors zone franc.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Référentiel ouvert au monde ----
        Schema::table('pays', function (Blueprint $t) {
            $t->enum('zone', ['uemoa', 'cedeao', 'afrique', 'europe', 'ameriques', 'asie', 'autre'])
              ->default('autre')->after('devise');
            $t->boolean('ouvert_a_la_vente')->default(false)->after('actif')
              ->comment('Un client de ce pays peut commander');
            $t->boolean('ouvert_aux_boutiques')->default(false)->after('ouvert_a_la_vente');
            $t->enum('format_adresse', ['ouest_africain', 'postal', 'libre'])->default('postal')
              ->comment('ouest_africain = quartier + repère ; postal = rue + code postal');
        });

        // Sert UNIQUEMENT à l'affichage : le règlement reste en francs
        // CFA, la plateforme ne prend aucun risque de change.
        Schema::create('taux_change', function (Blueprint $t) {
            $t->increments('id');
            $t->char('devise', 3);
            $t->decimal('xof_pour_une_unite', 14, 6)->comment('1 EUR = 655,957 XOF');
            $t->string('source', 60);
            $t->decimal('marge_pct', 5, 2)->default(2)
              ->comment('Sans marge, un taux affiché le matin est faux l\'après-midi');
            $t->dateTime('date_debut');
            $t->dateTime('date_fin')->nullable();
            $t->index(['devise', 'date_debut']);
        });

        // ---- Commandes : adresse internationale et mandataire ----
        Schema::table('commandes', function (Blueprint $t) {
            $t->unsignedTinyInteger('pays_livraison_id')->nullable()->after('pays_id');
            $t->string('adresse_ligne1', 160)->nullable()->after('quartier');
            $t->string('adresse_ligne2', 160)->nullable()->after('adresse_ligne1');
            $t->string('code_postal', 20)->nullable()->after('adresse_ligne2');
            $t->string('ville_texte', 120)->nullable()->after('code_postal');

            // La personne qui paie n'est pas toujours celle qui commande :
            // la diaspora commande, un parent règle sur place.
            $t->string('paye_par_nom', 120)->nullable()->after('client_telephone');
            $t->string('paye_par_telephone', 20)->nullable()->after('paye_par_nom');
            $t->string('paye_par_lien', 60)->nullable()->comment('parent, ami, mandataire');

            $t->string('destinataire_nom', 120)->nullable()->after('ville_texte');
            $t->string('destinataire_telephone', 20)->nullable()->after('destinataire_nom');

            // Devise dans laquelle le prix a été ANNONCÉ, et son taux.
            // Conservés pour pouvoir répondre à « vous m'aviez dit 45 euros ».
            $t->char('devise_affichage', 3)->nullable();
            $t->decimal('taux_affichage', 14, 6)->nullable();

            $t->foreign('pays_livraison_id')->references('id')->on('pays')->restrictOnDelete();
        });

        // « quartier » ne veut rien dire hors d'Afrique de l'Ouest.
        DB::statement('ALTER TABLE commandes MODIFY COLUMN quartier VARCHAR(120) NULL');
        DB::statement("ALTER TABLE commandes
            MODIFY COLUMN canal ENUM('web','mobile','telephone','agent','comptoir','whatsapp')
            NOT NULL DEFAULT 'web'");
        DB::statement("ALTER TABLE commandes
            MODIFY COLUMN mode_livraison ENUM('domicile','point_relais','retrait_boutique',
            'remise_transitaire','expedition_sur_devis','emporte') NOT NULL DEFAULT 'domicile'");
        DB::statement("ALTER TABLE commandes
            MODIFY COLUMN mode_paiement ENUM('mobile_money','carte','virement','especes_livraison',
            'especes_comptoir','mobile_money_comptoir') NOT NULL");

        // L'argent d'une vente au comptoir n'a jamais transité par la
        // plateforme : ni séquestre, ni reversement, seulement constat.
        DB::statement("ALTER TABLE sous_commandes
            MODIFY COLUMN etat_fonds ENUM('attente_encaissement','sequestre','reverse',
            'rembourse','impaye','hors_plateforme') NOT NULL DEFAULT 'sequestre'");
        DB::statement("ALTER TABLE sous_commandes
            MODIFY COLUMN statut ENUM('a_preparer','prete','expediee','livree','retournee',
            'annulee','vendue_comptoir') NOT NULL DEFAULT 'a_preparer'");
        Schema::table('sous_commandes', function (Blueprint $t) {
            $t->boolean('vente_comptoir')->default(false)->after('statut')
              ->comment('Vente présentielle, hors circuit de paiement de la plateforme');
        });

        // ---- Expédition déléguée ----
        Schema::create('remises_transitaire', function (Blueprint $t) {
            $t->id();
            $t->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $t->foreignId('sous_commande_id')->nullable()->constrained('sous_commandes')->nullOnDelete();

            // Coordonnées fournies PAR LE CLIENT. On ne les valide pas :
            // ce transitaire est un tiers qu'il choisit et mandate.
            $t->string('transitaire_nom', 160);
            $t->string('transitaire_contact', 120)->nullable();
            $t->string('transitaire_telephone', 20)->nullable();
            $t->string('adresse_remise');
            $t->string('reference_client', 80)->nullable();
            $t->text('instructions')->nullable();

            $t->enum('statut', ['a_remettre', 'remis', 'refuse_transitaire', 'annule'])->default('a_remettre');

            // PREUVE DE REMISE : c'est elle qui met fin à la
            // responsabilité de la plateforme. Sans elle, un colis
            // perdu en mer redevient notre problème.
            $t->dateTime('remis_le')->nullable();
            $t->foreignId('remis_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->string('recu_par_nom', 120)->nullable();
            $t->string('recu_par_piece', 60)->nullable();
            $t->unsignedBigInteger('preuve_media_id')->nullable();
            $t->unsignedSmallInteger('nb_colis')->nullable();
            $t->unsignedInteger('poids_total_g')->nullable();

            $t->string('decharge_version', 20)->nullable();
            $t->dateTime('decharge_acceptee_le')->nullable();
            $t->timestamp('cree_le')->useCurrent();
            $t->index(['commande_id', 'statut']);
        });

        // Traitement MANUEL et assumé : on pèse, on consulte, on
        // propose un prix ferme. Aucune grille tarifaire à maintenir.
        Schema::create('demandes_expedition', function (Blueprint $t) {
            $t->id();
            $t->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $t->string('reference', 28)->unique();
            $t->unsignedTinyInteger('pays_destination_id');
            $t->text('adresse_destination');
            $t->enum('delai_souhaite', ['economique', 'standard', 'express'])->default('standard');
            $t->text('commentaire_client')->nullable();

            $t->unsignedInteger('poids_estime_g')->nullable();
            $t->string('dimensions_cm', 40)->nullable();
            $t->string('transporteur_propose', 80)->nullable();
            $t->unsignedSmallInteger('delai_estime_jours')->nullable();

            // Montant FERME : si le transport coûte plus cher, l'écart
            // est pour nous. Un devis révisé après acceptation détruit
            // la confiance en une seule commande.
            $t->unsignedInteger('montant_devis_cfa')->nullable();
            $t->char('devise_affichage', 3)->nullable();
            $t->decimal('montant_affiche', 12, 2)->nullable();
            $t->date('valable_jusqu_au')->nullable();

            $t->enum('statut', ['demande', 'en_evaluation', 'propose', 'accepte',
                                'refuse', 'expire', 'expedie'])->default('demande');
            $t->text('motif_refus')->nullable();
            $t->string('transporteur_reel', 80)->nullable();
            $t->string('numero_suivi', 80)->nullable();
            $t->dateTime('expedie_le')->nullable();

            // Mention non négociable, écrite sur le devis et acceptée :
            // un client qui découvre 80 euros de douane sans avoir été
            // prévenu refuse le colis et ne revient jamais.
            $t->boolean('droits_a_la_charge_du_client')->default(true);

            $t->foreignId('traite_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->timestamp('demande_le')->useCurrent();
            $t->dateTime('propose_le')->nullable();
            $t->dateTime('repondu_le')->nullable();
            $t->index(['statut', 'demande_le']);
            $t->foreign('pays_destination_id')->references('id')->on('pays')->restrictOnDelete();
        });

        // ---- Boutiques : origine étrangère, exploitation locale ----
        Schema::table('boutiques', function (Blueprint $t) {
            // Un commerçant chinois installé à Ouagadougou tient une
            // boutique LOCALE : réglée en francs CFA comme les autres.
            // Mais ses produits viennent d'ailleurs, et le client a le
            // droit de le savoir. Deux notions, deux colonnes.
            $t->unsignedTinyInteger('pays_origine_id')->nullable()->after('pays_id')
              ->comment('Provenance des produits — affichée au client');
            $t->enum('type_boutique', ['artisan', 'revendeur', 'importateur', 'cooperative'])
              ->default('artisan')->after('niveau');
            $t->boolean('vend_en_ligne')->default(true)->after('type_boutique');
            $t->boolean('vend_au_comptoir')->default(true)->after('vend_en_ligne');
            // Nulle par défaut, et c'est délibéré : avec une commission
            // sur le comptoir, aucun vendeur ne déclarerait ses ventes
            // présentielles, et le stock resterait faux.
            $t->decimal('taux_commission_comptoir', 5, 2)->default(0)->after('taux_commission');
            $t->foreign('pays_origine_id')->references('id')->on('pays')->nullOnDelete();
        });

        // ---- Journal de stock ----
        // Sans lui, on constate un écart d'inventaire sans jamais
        // savoir d'où il vient.
        Schema::create('mouvements_stock', function (Blueprint $t) {
            $t->id();
            $t->foreignId('variante_id')->constrained('variantes_produit')->cascadeOnDelete();
            $t->enum('type', ['vente_en_ligne', 'vente_comptoir', 'retour', 'reapprovisionnement',
                              'inventaire', 'casse', 'perte', 'correction']);
            $t->integer('quantite')->comment('Négatif pour une sortie');
            $t->integer('stock_avant');
            $t->integer('stock_apres');
            $t->string('piece_type', 30)->nullable();
            $t->unsignedBigInteger('piece_id')->nullable();
            $t->string('motif')->nullable();
            $t->foreignId('auteur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $t->timestamp('cree_le')->useCurrent();
            $t->index(['variante_id', 'cree_le']);
            $t->index(['type', 'cree_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
        Schema::dropIfExists('demandes_expedition');
        Schema::dropIfExists('remises_transitaire');
        Schema::dropIfExists('taux_change');
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn([
            'pays_origine_id', 'type_boutique', 'vend_en_ligne', 'vend_au_comptoir', 'taux_commission_comptoir']));
        Schema::table('sous_commandes', fn (Blueprint $t) => $t->dropColumn('vente_comptoir'));
        Schema::table('commandes', fn (Blueprint $t) => $t->dropColumn([
            'pays_livraison_id', 'adresse_ligne1', 'adresse_ligne2', 'code_postal', 'ville_texte',
            'paye_par_nom', 'paye_par_telephone', 'paye_par_lien',
            'destinataire_nom', 'destinataire_telephone', 'devise_affichage', 'taux_affichage']));
        Schema::table('pays', fn (Blueprint $t) => $t->dropColumn([
            'zone', 'ouvert_a_la_vente', 'ouvert_aux_boutiques', 'format_adresse']));
    }
};

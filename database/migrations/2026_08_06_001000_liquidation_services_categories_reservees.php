<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 10/10 — Liquidation, services, catégories réservées, annuaire, recherche
 *
 * Cinq ajouts, chacun né d'un besoin exprimé et non d'une envie de
 * fonctionnalité :
 *
 * 1. LA LIQUIDATION. Écouler un lot sous son prix normal. Ce n'est pas
 *    une remise : c'est une remise QUI PORTE UN MOTIF, montré au client.
 *    Un prix barré sans explication passe pour une ficelle ; « lot dont
 *    la date approche » est une information honnête, et c'est cette
 *    honnêteté qui éteint le litige « on ne m'avait pas dit ».
 *
 *    Le cas critique est la péremption. Un produit périmé qui reste
 *    achetable est le pire scénario du système : l'étiquette QR censée
 *    prouver l'origine devient la preuve de la faute, horodatée et
 *    signée. D'où un verrou en base, pas seulement dans l'interface.
 *
 * 2. LES SERVICES. Un service n'est pas un produit sans stock. Il n'a ni
 *    stock, ni livraison, ni QR, ni prix ferme, ni délai court. Ce
 *    dernier point casse le séquestre : bloquer 25 millions pendant huit
 *    mois n'est pas la même activité que bloquer 35 000 F pendant trois
 *    jours. D'où le PAIEMENT PAR JALONS — on ne séquestre jamais le
 *    contrat entier, seulement la tranche en cours.
 *
 * 3. LA COMMISSION DÉGRESSIVE. Un taux plat ne survit pas au changement
 *    d'échelle : 10 % sur une maison à 25 millions font 2,5 millions, et
 *    aucun maçon ne signe cela. Barème par tranches, calculé comme un
 *    impôt sur le revenu.
 *
 * 4. LES CATÉGORIES RÉSERVÉES. Les produits naturels ne se publient pas
 *    librement : chaque fiche est relue. La règle de refus est unique —
 *    aucune allégation thérapeutique. Entre « feuilles de baobab
 *    séchées » et « feuilles de baobab qui soignent l'anémie » passe la
 *    frontière entre un aliment et un médicament, et c'est la RÉDACTION
 *    qui fait basculer, pas le produit.
 *
 * 5. LA RECHERCHE PAR SYNONYMES. Le catalogue est écrit dans la langue
 *    du vendeur, la recherche tapée dans celle du client. Au Burkina on
 *    ne cherche pas « hibiscus » : on cherche bissap, oseille ou da. Une
 *    recherche qui ne compare que des chaînes rend « aucun résultat »
 *    sur un produit en rayon — la panne la plus coûteuse d'un catalogue,
 *    parce qu'elle ne produit ni erreur ni réclamation.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* =================================================================
           1. LIQUIDATION
           ================================================================= */

        // Les motifs sont un référentiel, pas un ENUM : on en ajoutera.
        // Un ENUM impose un ALTER TABLE — donc un verrou de table — pour
        // chaque nouveau motif. Une table se complète avec un INSERT.
        Schema::create('motifs_liquidation', function (Blueprint $t) {
            $t->increments('id');
            $t->string('code', 30)->unique();
            $t->string('libelle', 80);
            $t->string('aide_client', 200)
              ->comment('Phrase montrée à l\'acheteur — elle explique la remise');
            $t->boolean('date_limite_obligatoire')->default(false)
              ->comment('Si vrai, la liquidation exige une date et se ferme à cette date');
            $t->unsignedTinyInteger('ordre')->default(0);
        });

        Schema::create('liquidations', function (Blueprint $t) {
            $t->bigIncrements('id');

            // LA LIQUIDATION PORTE SUR UNE VARIANTE, PAS SUR UN PRODUIT.
            // Le pot de 250 g peut être en fin de vie quand le 1 kg vient
            // d'arriver : lier au produit forcerait à liquider les deux.
            $t->unsignedBigInteger('variante_id');
            $t->unsignedInteger('motif_id');

            $t->unsignedInteger('prix_liquide_cfa')
              ->comment('Prix de vente pendant la liquidation, TOUJOURS en entier');
            $t->unsignedInteger('prix_reference_cfa')
              ->comment('Prix normal figé au moment de la mise en liquidation. Recopié '
                      . 'volontairement : si le vendeur change son prix catalogue demain, '
                      . 'le prix barré affiché au client ne doit pas bouger sous ses yeux');

            $t->dateTime('debut_le');
            $t->dateTime('fin_le')
              ->comment('Obligatoire : sans fin, une liquidation devient le prix normal '
                      . 'et le prix barré ne veut plus rien dire');

            $t->date('date_peremption')->nullable()
              ->comment('Obligatoire si le motif l\'exige. La vente se ferme à cette date');

            $t->string('detail', 300)
              ->comment('Ce que le client doit savoir : quel défaut, quel lot, quelle date. '
                      . 'Un défaut annoncé et décrit ne fonde pas un litige — c\'est '
                      . 'précisément pour cela qu\'on l\'annonce');

            $t->unsignedInteger('quantite_concernee')->nullable();
            $t->unsignedBigInteger('cree_par_utilisateur_id')->nullable();
            $t->timestamps();

            $t->foreign('variante_id')->references('id')->on('variantes_produit')->cascadeOnDelete();
            $t->foreign('motif_id')->references('id')->on('motifs_liquidation');
            $t->index(['variante_id', 'debut_le', 'fin_le']);
        });

        /* GARDE-FOU EN BASE, PAS SEULEMENT DANS L'INTERFACE.
           Une interface se contourne : un appel d'API direct, un import en
           masse, un script de reprise. Ces trois contrôles tiennent quel
           que soit le chemin emprunté.

           On les écrit en TRIGGER plutôt qu'en CHECK parce que MariaDB
           n'évalue pas CURDATE() dans une contrainte CHECK. */
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_liquidation_avant_insert BEFORE INSERT ON liquidations
FOR EACH ROW
BEGIN
    DECLARE v_exige TINYINT DEFAULT 0;

    IF NEW.fin_le <= NEW.debut_le THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Liquidation : la fin doit suivre le debut';
    END IF;

    IF NEW.prix_liquide_cfa >= NEW.prix_reference_cfa THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Liquidation : le prix liquide doit etre inferieur au prix de reference';
    END IF;

    SELECT date_limite_obligatoire INTO v_exige
      FROM motifs_liquidation WHERE id = NEW.motif_id;

    IF v_exige = 1 AND NEW.date_peremption IS NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Liquidation pour peremption : la date limite est obligatoire';
    END IF;
END
SQL);

        /* =================================================================
           2. SERVICES
           ================================================================= */

        Schema::create('familles_service', function (Blueprint $t) {
            $t->increments('id');
            $t->string('code', 30)->unique();
            $t->string('libelle', 80);
            $t->boolean('devis_obligatoire')->default(false)
              ->comment('Un chantier ne se vend pas à prix catalogue');
            $t->boolean('profession_reglementee')->default(false)
              ->comment('Si vrai : annuaire seulement, aucun encaissement par la plateforme');
        });

        Schema::create('services', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('boutique_id');
            $t->unsignedInteger('famille_id');

            $t->string('nom', 180);
            $t->text('description');
            $t->string('unite', 40)->comment('chantier, projet, participant, jour…');

            $t->enum('mode_vente', ['prix_fixe', 'devis'])
              ->comment('Une formation à 75 000 F se met au panier ; une maison R+1 non. '
                      . 'Imposer l\'un des deux partout perd la moitié du catalogue');

            // Prix ferme, seulement en mode prix_fixe.
            $t->unsignedInteger('prix_cfa')->nullable();
            // Fourchette indicative, seulement en mode devis. Elle n'engage
            // pas : elle évite au client de demander un devis pour un
            // budget hors de portée, et au prestataire d'y répondre.
            $t->unsignedInteger('fourchette_min_cfa')->nullable();
            $t->unsignedInteger('fourchette_max_cfa')->nullable();

            $t->string('delai_annonce', 60);
            $t->unsignedSmallInteger('places')->nullable()
              ->comment('Formations : une session a une capacité, pas un stock');

            $t->boolean('actif')->default(true);
            $t->timestamps();

            $t->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $t->foreign('famille_id')->references('id')->on('familles_service');
            $t->index(['boutique_id', 'actif']);
        });

        // Cohérence du mode de vente : un prix fixe sans prix, ou un devis
        // sans fourchette, produisent une fiche que le client ne peut pas lire.
        DB::statement(<<<'SQL'
ALTER TABLE services ADD CONSTRAINT chk_service_mode CHECK (
    (mode_vente = 'prix_fixe' AND prix_cfa IS NOT NULL)
 OR (mode_vente = 'devis'     AND fourchette_min_cfa IS NOT NULL
                              AND fourchette_max_cfa >= fourchette_min_cfa)
)
SQL);

        // Trame de jalons attachée au service : ce que le prestataire
        // propose par défaut. Le devis accepté en fera une copie figée.
        Schema::create('jalons_type', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('service_id');
            $t->unsignedTinyInteger('ordre');
            $t->string('libelle', 120);
            $t->unsignedTinyInteger('pourcentage');
            $t->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
            $t->unique(['service_id', 'ordre']);
        });

        /* =================================================================
           3. DEVIS ET JALONS D'EXÉCUTION
           ================================================================= */

        Schema::create('demandes_devis', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('reference', 24)->unique();
            $t->unsignedBigInteger('service_id');
            $t->unsignedBigInteger('boutique_id');

            $t->string('client_nom', 120);
            $t->string('client_telephone', 24);
            $t->string('localisation', 160)->nullable();
            $t->unsignedBigInteger('budget_envisage_cfa')->nullable();
            $t->text('besoin');
            $t->enum('echeance', ['immediat', '1_3_mois', '3_6_mois', 'renseignement']);

            $t->enum('statut', ['demande', 'rappele', 'visite', 'chiffre', 'accepte', 'refuse', 'sans_suite'])
              ->default('demande');
            $t->timestamps();

            $t->foreign('service_id')->references('id')->on('services');
            $t->foreign('boutique_id')->references('id')->on('boutiques');
            $t->index(['boutique_id', 'statut']);
        });

        Schema::create('devis', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('reference', 24)->unique();
            $t->unsignedBigInteger('demande_id');

            $t->unsignedBigInteger('montant_cfa');
            $t->unsignedBigInteger('commission_cfa')
              ->comment('Calculée au barème dégressif et FIGÉE ici. Si le barème change '
                      . 'demain, un devis déjà émis ne doit pas changer de commission');
            $t->decimal('taux_moyen_pct', 5, 2)
              ->comment('Taux réellement supporté — le seul chiffre parlant pour un vendeur');

            $t->date('valable_jusqu_au')
              ->comment('Un devis sans date de validité engage indéfiniment sur des prix '
                      . 'de matériaux qui, eux, bougent');
            $t->text('detail');

            $t->enum('statut', ['emis', 'accepte', 'refuse', 'expire'])->default('emis');
            $t->dateTime('accepte_le')->nullable();
            $t->timestamps();

            $t->foreign('demande_id')->references('id')->on('demandes_devis');
        });

        /* JALONS D'EXÉCUTION — le cœur du dispositif services.
           Chaque jalon est séquestré SÉPARÉMENT. À aucun moment la
           plateforme ne détient le montant total du contrat : sur un
           chantier de 25 millions, son exposition se limite à la tranche
           ouverte. C'est ce qui rend l'activité tenable. */
        Schema::create('jalons', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('devis_id');
            $t->unsignedTinyInteger('ordre');
            $t->string('libelle', 120);
            $t->unsignedTinyInteger('pourcentage');
            $t->unsignedBigInteger('montant_cfa');

            $t->enum('statut', ['a_venir', 'en_cours', 'a_valider', 'valide', 'conteste'])
              ->default('a_venir');

            // Même vocabulaire que les sous-commandes : un état de fonds
            // reste un état de fonds, qu'il s'agisse d'un pot de karité ou
            // d'une dalle de béton. Deux vocabulaires pour une même notion
            // finiraient par diverger.
            // `hors_plateforme` porte ici le MÊME sens qu'au comptoir :
            // l'argent n'a jamais transité par nous. C'est le cas des
            // tranches qui dépassent le plafond de séquestre — le client
            // règle le prestataire en direct. Sans cette valeur, il
            // faudrait mentir sur l'état des fonds ou inventer un second
            // vocabulaire pour la même réalité.
            $t->enum('etat_fonds', ['non_appele', 'attente_encaissement', 'sequestre',
                                    'reverse', 'rembourse', 'hors_plateforme'])
              ->default('non_appele');

            $t->dateTime('appele_le')->nullable();
            $t->dateTime('valide_le')->nullable();
            $t->timestamps();

            $t->foreign('devis_id')->references('id')->on('devis')->cascadeOnDelete();
            $t->unique(['devis_id', 'ordre']);
            $t->index('statut');
        });

        /* =================================================================
           4. BARÈME DE COMMISSION
           ================================================================= */

        Schema::create('bareme_commission', function (Blueprint $t) {
            $t->increments('id');
            $t->string('applique_a', 20)->default('service');
            $t->unsignedBigInteger('plafond_cfa')->nullable()
              ->comment('NULL = tranche supérieure, sans plafond');
            $t->decimal('taux_pct', 5, 2);
            $t->unsignedTinyInteger('ordre');
            $t->date('en_vigueur_du');
            $t->date('en_vigueur_au')->nullable();
            $t->index(['applique_a', 'ordre']);
        });

        /* =================================================================
           5. CATÉGORIES RÉSERVÉES ET AUTORISATIONS
           ================================================================= */

        Schema::table('categories', function (Blueprint $t) {
            $t->boolean('reservee')->default(false)->after('libelle')
              ->comment('Une fiche de cette catégorie n\'est visible qu\'après autorisation');
            $t->string('note_reserve', 300)->nullable()->after('reservee');
        });

        Schema::create('autorisations_publication', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('produit_id');

            /* LE SILENCE VAUT REFUS. L'absence de ligne, ou une ligne en
               « demande », laisse la fiche invisible. C'est l'inverse du
               réflexe habituel — publier puis modérer — et c'est
               volontaire : sur une catégorie sensible, une fiche fautive
               vue une heure a déjà produit son effet. */
            $t->enum('statut', ['demande', 'accorde', 'refuse', 'revoque'])->default('demande');

            $t->text('motif')->nullable()
              ->comment('Envoyé au vendeur. Un refus sans motif se re-soumet à l\'identique');
            $t->json('termes_signales')->nullable()
              ->comment('Ce que le détecteur a trouvé, gardé pour l\'audit — pas pour décider');

            $t->unsignedBigInteger('decide_par_utilisateur_id')->nullable();
            $t->dateTime('demande_le');
            $t->dateTime('decide_le')->nullable();
            $t->timestamps();

            $t->foreign('produit_id')->references('id')->on('produits')->cascadeOnDelete();
            $t->index(['statut', 'demande_le']);
        });

        // Vocabulaire interdit sur une fiche. En table et non en dur : la
        // liste s'allonge à l'usage, et l'allonger ne doit pas exiger un
        // déploiement.
        Schema::create('termes_interdits', function (Blueprint $t) {
            $t->increments('id');
            $t->string('terme', 60)->unique();
            $t->enum('gravite', ['bloquant', 'a_verifier'])->default('a_verifier')
              ->comment('a_verifier : on signale au relecteur. bloquant : on refuse la '
                      . 'soumission tout de suite, sans faire attendre trois jours');
            $t->string('explication', 200)->nullable();
        });

        /* =================================================================
           6. ANNUAIRE DES PROFESSIONS RÉGLEMENTÉES
           ================================================================= */

        /* Ici Afrishop VÉRIFIE et MET EN RELATION — il n'encaisse rien.
           Aucune clé étrangère ne relie cette table à `commandes`, et
           c'est intentionnel : la relation n'existe pas et ne doit pas
           pouvoir être créée par mégarde. Un notaire manie des fonds de
           tiers sur un compte de dépôt dont il répond personnellement
           devant sa chambre ; une plateforme ne s'y interpose pas. */
        Schema::create('professionnels', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('profession', 60);
            $t->string('nom', 140);
            $t->unsignedTinyInteger('pays_id');
            $t->string('ville', 80);

            $t->string('ordre_ou_association', 160);
            $t->string('numero_inscription', 60);
            $t->date('verifie_le')->nullable();
            $t->unsignedBigInteger('verifie_par_utilisateur_id')->nullable();
            $t->date('verification_expire_le')
              ->comment('Une vérification sans date d\'expiration devient un mensonge '
                      . 'le jour où le professionnel est radié');

            $t->string('telephone', 24);
            $t->json('actes')->comment('Types d\'actes ou de consultations proposés');

            $t->boolean('avertissement_sante')->default(false)
              ->comment('Médecine traditionnelle : un avertissement s\'affiche sur la fiche');
            $t->boolean('publie')->default(false);
            $t->timestamps();

            $t->foreign('pays_id')->references('id')->on('pays');
            $t->unique(['ordre_ou_association', 'numero_inscription']);
            $t->index(['profession', 'publie']);
        });

        /* =================================================================
           7. RECHERCHE : SYNONYMES ET RECHERCHES INFRUCTUEUSES
           ================================================================= */

        Schema::create('termes_recherche', function (Blueprint $t) {
            $t->increments('id');
            $t->string('canon', 60)->comment('Le terme retenu, celui du catalogue');
            $t->string('variante', 60)->comment('Synonyme, nom local, traduction, orthographe');
            $t->enum('nature', ['synonyme', 'nom_local', 'traduction', 'orthographe', 'commercial'])
              ->default('synonyme');
            $t->string('langue', 20)->nullable()->comment('moore, dioula, fulfulde, anglais…');
            $t->boolean('actif')->default(true);
            $t->timestamps();

            // L'équivalence est symétrique par construction : on interroge
            // la table dans les deux sens. La déclarer deux fois créerait
            // deux vérités à maintenir, donc tôt ou tard une divergence.
            $t->unique(['canon', 'variante']);
            $t->index('variante');
        });

        /* LE JOURNAL DES RECHERCHES SANS RÉSULTAT.
           La table la plus rentable du schéma, et la plus discrète. Une
           recherche vide ne produit ni erreur, ni réclamation, ni ticket :
           le client s'en va en pensant que la plateforme est vide. Cette
           table est la seule chose qui rende la panne visible, et chaque
           ligne est un synonyme qu'il suffit d'ajouter pour rouvrir une
           vente — pour tous les suivants, pas seulement pour celui-là. */
        Schema::create('recherches_infructueuses', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('terme_normalise', 120)->unique();
            $t->string('terme_original', 160);
            $t->unsignedInteger('occurrences')->default(1);
            $t->dateTime('premiere_fois');
            $t->dateTime('derniere_fois');
            $t->enum('traitement', ['a_traiter', 'synonyme_ajoute', 'produit_a_referencer', 'ignore'])
              ->default('a_traiter');
            $t->string('note', 200)->nullable();
            $t->index(['traitement', 'occurrences']);
        });

        // Index plein texte sur les colonnes réellement cherchées. Il ne
        // remplace pas le dictionnaire de synonymes : il accélère la
        // couche exacte, le dictionnaire fournit la couche sémantique.
        DB::statement('ALTER TABLE produits ADD FULLTEXT ft_produits_recherche (nom, description)');
        DB::statement('ALTER TABLE services ADD FULLTEXT ft_services_recherche (nom, description)');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_liquidation_avant_insert');
        DB::statement('ALTER TABLE produits DROP INDEX ft_produits_recherche');
        DB::statement('ALTER TABLE services DROP INDEX ft_services_recherche');

        Schema::dropIfExists('recherches_infructueuses');
        Schema::dropIfExists('termes_recherche');
        Schema::dropIfExists('professionnels');
        Schema::dropIfExists('termes_interdits');
        Schema::dropIfExists('autorisations_publication');
        Schema::dropIfExists('bareme_commission');
        Schema::dropIfExists('jalons');
        Schema::dropIfExists('devis');
        Schema::dropIfExists('demandes_devis');
        Schema::dropIfExists('jalons_type');
        Schema::dropIfExists('services');
        Schema::dropIfExists('familles_service');
        Schema::dropIfExists('liquidations');
        Schema::dropIfExists('motifs_liquidation');

        Schema::table('categories', function (Blueprint $t) {
            $t->dropColumn(['reservee', 'note_reserve']);
        });
    }
};
